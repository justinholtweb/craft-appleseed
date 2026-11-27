<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\db\ActiveQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\appleseed\events\ScanEvent;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\helpers\LinkText;
use justinholtweb\appleseed\helpers\LinkUrl;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\jobs\CheckLinksJob;
use justinholtweb\appleseed\jobs\ScanEntryJob;
use justinholtweb\appleseed\jobs\ScanJob;
use justinholtweb\appleseed\models\ScanResult;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\ScanRecord;
use yii\db\Query;

class Scanner extends Component
{
    /**
     * @event ScanEvent Triggered after a scan completes, once its tallies are final and before
     * notifications go out.
     * @since 5.3.0
     */
    public const EVENT_AFTER_SCAN = 'afterScan';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** A running scan that hasn't been touched for this long is treated as abandoned. */
    public const ABANDONED_AFTER_SECONDS = 7200;

    /**
     * Run a full scan synchronously: discover links, check them, report results.
     */
    public function runFullScan(?callable $progressCallback = null): ScanRecord
    {
        return $this->runScan(ScanType::FULL, progressCallback: $progressCallback);
    }

    /**
     * Run a database-only scan synchronously.
     */
    public function runDatabaseScan(?callable $progressCallback = null): ScanRecord
    {
        return $this->runScan(ScanType::DATABASE, progressCallback: $progressCallback);
    }

    /**
     * Run a scan for a single entry synchronously.
     */
    public function runEntryScan(int $entryId, ?int $siteId = null, ?callable $progressCallback = null): ScanRecord
    {
        return $this->runScan(ScanType::ENTRY, $entryId, $siteId, progressCallback: $progressCallback);
    }

    /**
     * Run a scan limited to specific sections synchronously.
     *
     * @param int[] $sectionIds
     */
    public function runSectionScan(array $sectionIds, ?callable $progressCallback = null): ScanRecord
    {
        return $this->runScan(ScanType::SECTION, sectionIds: $sectionIds, progressCallback: $progressCallback);
    }

    /**
     * Run a scan of any type start-to-finish in the current process.
     *
     * @param int[]|null $sectionIds
     */
    public function runScan(string $type, ?int $entryId = null, ?int $siteId = null, ?array $sectionIds = null, ?callable $progressCallback = null): ScanRecord
    {
        $scan = $this->startScan($type, $entryId, $siteId, $sectionIds, $progressCallback);

        try {
            if ($progressCallback) {
                $progressCallback('Checking links...', 0);
            }

            $total = $scan->totalLinksFound;
            $checked = 0;

            foreach ($this->getScanLinkQuery($scan->id)->each() as $linkRecord) {
                /** @var LinkRecord $linkRecord */
                if ($checked % 25 === 0 && !$this->isScanRunning($scan->id)) {
                    $scan->refresh();
                    return $scan;
                }

                $this->checkScanLink($linkRecord, $scan);
                $checked++;

                if ($checked % 25 === 0) {
                    $this->updateScanProgress($scan);
                }

                if ($progressCallback) {
                    $progressCallback("Checked {$checked} of {$total} links", $checked / max(1, $total));
                }
            }

            $this->finalizeScan($scan);
        } catch (\Throwable $e) {
            $this->failScan($scan);
            Craft::error("Appleseed scan failed: {$e->getMessage()}", __METHOD__);
            throw $e;
        }

        return $scan;
    }

    /**
     * Begin a scan: create the scan record and discover + persist its links.
     *
     * The returned scan is left in the `running` state with its links stored
     * (tagged with `lastScanId`) but not yet checked. Callers finish the scan
     * either synchronously (see {@see runScan()}) or in batches via the
     * queue (see the CheckLinksBatchedJob).
     *
     * @param int[]|null $sectionIds
     */
    public function startScan(string $type, ?int $entryId = null, ?int $siteId = null, ?array $sectionIds = null, ?callable $progressCallback = null): ScanRecord
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        // An incremental scan needs something to be incremental to. Before the first completed
        // scan, it reads every entry.
        $changedSince = null;
        if ($type === ScanType::INCREMENTAL) {
            $changedSince = $this->_lastContentScanStart();
            if ($changedSince === null) {
                $type = ScanType::DATABASE;
            }
        }

        $scan = new ScanRecord();
        $scan->status = self::STATUS_RUNNING;
        $scan->type = $type;
        $scan->startedAt = Db::prepareDateForDb(new DateTime());
        $scan->entryId = $entryId;
        $scan->save(false);

        // Keep the scan looking alive while discovery runs, so a long crawl isn't mistaken for an
        // abandoned scan.
        $lastTouch = time();
        $touchingCallback = function(string $message, float $progress) use ($scan, $progressCallback, &$lastTouch) {
            if (time() - $lastTouch >= 60) {
                $lastTouch = time();
                $this->touchScan($scan);
            }

            if ($progressCallback) {
                $progressCallback($message, $progress);
            }
        };

        try {
            $touchingCallback('Discovering links...', 0);

            if ($type === ScanType::RECHECK) {
                $scan->totalLinksFound = $this->_tagBrokenLinks($scan->id);
            } else {
                $discovery = $this->_discoverLinks($type, $entryId, $siteId, $sectionIds, $changedSince, $settings, $touchingCallback);
                $scan->totalLinksFound = $this->_storeLinks($type, $sectionIds, $discovery, $scan->id, $settings);
            }

            $scan->save(false);
        } catch (\Throwable $e) {
            $this->failScan($scan);
            Craft::error("Appleseed scan discovery failed: {$e->getMessage()}", __METHOD__);
            throw $e;
        }

        return $scan;
    }

    /**
     * A query for the links belonging to a scan that still need checking.
     *
     * The result set is intentionally stable for the duration of the scan
     * (filtered only on immutable columns and ordered by id), so it can be
     * sliced across multiple batched queue jobs without skipping or
     * double-processing items as link statuses change.
     */
    public function getScanLinkQuery(int $scanId): ActiveQuery
    {
        return LinkRecord::find()
            ->where(['lastScanId' => $scanId, 'isIgnored' => false])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * Check a single link belonging to a scan and persist the result.
     *
     * Ignored links and — when external checking is disabled — external links
     * are skipped without touching the network.
     */
    public function checkScanLink(LinkRecord $linkRecord, ScanRecord $scan): void
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        if ($linkRecord->isIgnored) {
            return;
        }

        if (!$settings->checkExternalLinks && $this->isExternalUrl($linkRecord->url)) {
            return;
        }

        $this->applyResult($linkRecord, $plugin->linkChecker->checkUrl($linkRecord->url), $scan->id);
    }

    /**
     * Check one link right now, outside of any scan, and store the result.
     */
    public function checkLink(LinkRecord $linkRecord): ScanResult
    {
        $result = Plugin::getInstance()->linkChecker->checkUrl($linkRecord->url);
        $this->applyResult($linkRecord, $result);

        return $result;
    }

    /**
     * Store a check result on a link, keeping track of when it started failing.
     */
    public function applyResult(LinkRecord $linkRecord, ScanResult $result, ?int $scanId = null): void
    {
        $now = Db::prepareDateForDb(new DateTime());

        if ($result->isBroken()) {
            if (!LinkStatus::isBroken($linkRecord->status) || $linkRecord->firstBrokenAt === null) {
                $linkRecord->firstBrokenAt = $now;
            }
        } else {
            $linkRecord->firstBrokenAt = null;
        }

        $linkRecord->statusCode = $result->statusCode;
        $linkRecord->status = $result->status;
        $linkRecord->redirectUrl = $result->redirectUrl;
        $linkRecord->redirectChain = $result->redirectChain ? json_encode($result->redirectChain) : null;
        $linkRecord->errorMessage = $result->errorMessage;
        $linkRecord->lastCheckedAt = $now;
        if ($scanId !== null) {
            $linkRecord->lastScanId = $scanId;
        }
        $linkRecord->save(false);
    }

    /**
     * Refresh a running scan's tallies from the database (best-effort progress).
     */
    public function updateScanProgress(ScanRecord $scan): void
    {
        $this->_applyCounts($scan);
        $scan->save(false);
        $this->touchScan($scan);
    }

    /**
     * Mark a running scan as alive, so it isn't taken for abandoned (see {@see failAbandonedScans()}).
     * A save with nothing changed doesn't move `dateUpdated`, so this sets it directly.
     */
    public function touchScan(ScanRecord $scan): void
    {
        ScanRecord::updateAll(['dateUpdated' => Db::prepareDateForDb(new DateTime())], ['id' => $scan->id]);
    }

    /**
     * Whether a scan is still running — not finished, failed or cancelled.
     */
    public function isScanRunning(int $scanId): bool
    {
        return ScanRecord::find()->where(['id' => $scanId, 'status' => self::STATUS_RUNNING])->exists();
    }

    /**
     * Finalize a scan: tally results, mark it completed, and notify if needed.
     *
     * Counts are recomputed authoritatively from the stored link records, so
     * this is safe to call once at the end of a synchronous scan or after the
     * final batch of a queued scan. A scan that was cancelled stays cancelled.
     */
    public function finalizeScan(ScanRecord $scan): void
    {
        $plugin = Plugin::getInstance();

        $scan->refresh();
        if ($scan->status !== self::STATUS_RUNNING) {
            return;
        }

        $this->_applyCounts($scan);
        $scan->status = self::STATUS_COMPLETED;
        $scan->completedAt = Db::prepareDateForDb(new DateTime());
        $scan->save(false);

        if ($this->hasEventHandlers(self::EVENT_AFTER_SCAN)) {
            $this->trigger(self::EVENT_AFTER_SCAN, new ScanEvent(['scan' => $scan]));
        }

        $plugin->notifier->scanCompleted($scan);
    }

    /**
     * Mark a scan as failed.
     */
    public function failScan(ScanRecord $scan): void
    {
        $scan->status = self::STATUS_FAILED;
        $scan->completedAt = Db::prepareDateForDb(new DateTime());
        $scan->save(false);
    }

    /**
     * Stop every running scan. Queued checking jobs skip the rest of their links; results already
     * stored are kept.
     *
     * @return int How many scans were cancelled.
     */
    public function cancelRunningScans(): int
    {
        return ScanRecord::updateAll(
            ['status' => self::STATUS_CANCELLED, 'completedAt' => Db::prepareDateForDb(new DateTime()), 'dateUpdated' => Db::prepareDateForDb(new DateTime())],
            ['status' => self::STATUS_RUNNING],
        );
    }

    /**
     * Mark running scans nobody has touched for {@see ABANDONED_AFTER_SECONDS} as failed — their
     * queue job died — so they stop blocking the schedule and the dashboard's progress bar.
     */
    public function failAbandonedScans(): void
    {
        $cutoff = (new DateTime())->modify('-' . self::ABANDONED_AFTER_SECONDS . ' seconds');

        ScanRecord::updateAll(
            ['status' => self::STATUS_FAILED, 'completedAt' => Db::prepareDateForDb(new DateTime())],
            ['and', ['status' => self::STATUS_RUNNING], ['<', 'dateUpdated', Db::prepareDateForDb($cutoff)]],
        );
    }

    /**
     * Delete finished scans older than `$days` days. Links and their results are kept.
     *
     * @return int How many scans were deleted.
     */
    public function pruneScans(int $days): int
    {
        $cutoff = (new DateTime())->modify('-' . max(1, $days) . ' days');

        return ScanRecord::deleteAll([
            'and',
            ['not', ['status' => self::STATUS_RUNNING]],
            ['<', 'dateCreated', Db::prepareDateForDb($cutoff)],
        ]);
    }

    /**
     * Queue a scan. Returns false when one is already running.
     *
     * @param string $type One of {@see ScanType::STARTABLE}.
     * @param int[]|null $sectionIds Scan just these sections (any type but `recheck`).
     */
    public function queueScan(string $type = ScanType::FULL, ?array $sectionIds = null): bool
    {
        if (Plugin::getInstance()->reporting->getRunningScan() !== null) {
            return false;
        }

        $job = new ScanJob(['type' => $type]);
        if (!empty($sectionIds)) {
            $job->sectionIds = array_values(array_map('intval', $sectionIds));
        }

        Craft::$app->getQueue()->push($job);

        return true;
    }

    /**
     * Queue a scan of one entry.
     */
    public function queueEntryScan(int $entryId, ?int $siteId = null): void
    {
        Craft::$app->getQueue()->push(new ScanEntryJob([
            'entryId' => $entryId,
            'siteId' => $siteId,
        ]));
    }

    /**
     * Queue a re-check of particular links.
     *
     * @param int[] $linkIds
     */
    public function queueLinkChecks(array $linkIds): void
    {
        $linkIds = array_values(array_unique(array_map('intval', $linkIds)));

        if ($linkIds !== []) {
            Craft::$app->getQueue()->push(new CheckLinksJob(['linkIds' => $linkIds]));
        }
    }

    /**
     * Ignore a link: it's no longer checked, counted or reported, and the decision survives scans.
     */
    public function ignoreLink(LinkRecord $link, ?User $user = null): void
    {
        $link->isIgnored = true;
        $link->status = LinkStatus::IGNORED;
        $link->firstBrokenAt = null;
        $link->ignoredById = $user?->id;
        $link->dateIgnored = Db::prepareDateForDb(new DateTime());
        $link->save(false);
    }

    /**
     * Stop ignoring a link. It's checked again on the next scan.
     */
    public function unignoreLink(LinkRecord $link): void
    {
        $link->isIgnored = false;
        $link->status = LinkStatus::UNKNOWN;
        $link->ignoredById = null;
        $link->dateIgnored = null;
        $link->save(false);
    }

    /**
     * Whether a URL points somewhere other than one of this install's sites.
     */
    public function isExternalUrl(string $url): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        if (!$urlHost) {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteHost = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
            if ($siteHost && strcasecmp($urlHost, $siteHost) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recompute a scan's status tallies from its stored link records.
     */
    private function _applyCounts(ScanRecord $scan): void
    {
        $counts = $this->getScanLinkQuery($scan->id)
            ->select(['status', 'count' => 'COUNT(*)'])
            ->groupBy(['status'])
            ->orderBy([])
            ->asArray()
            ->all();

        $byStatus = array_column($counts, 'count', 'status');
        $broken = 0;
        foreach (LinkStatus::BROKEN_STATUSES as $status) {
            $broken += (int)($byStatus[$status] ?? 0);
        }

        $scan->brokenCount = $broken;
        $scan->redirectCount = (int)($byStatus[LinkStatus::REDIRECT] ?? 0);
        $scan->workingCount = (int)($byStatus[LinkStatus::WORKING] ?? 0);
        $scan->totalLinksChecked = array_sum(array_map('intval', $byStatus)) - (int)($byStatus[LinkStatus::UNKNOWN] ?? 0);

        $scan->newBrokenCount = $scan->startedAt === null ? 0 : (int)$this->getScanLinkQuery($scan->id)
            ->andWhere(['status' => LinkStatus::BROKEN_STATUSES])
            ->andWhere(['>=', 'firstBrokenAt', $scan->startedAt])
            ->count();
    }

    /**
     * When the last completed scan that read every entry's content started — what an incremental
     * scan reads changes since.
     */
    private function _lastContentScanStart(): ?DateTime
    {
        $startedAt = ScanRecord::find()
            ->select(['startedAt'])
            ->where(['status' => self::STATUS_COMPLETED, 'type' => [ScanType::FULL, ScanType::DATABASE, ScanType::INCREMENTAL]])
            ->orderBy(['startedAt' => SORT_DESC])
            ->scalar();

        if (!$startedAt) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($startedAt);

        return $date instanceof DateTime ? $date : null;
    }

    /**
     * Tag every broken link for re-checking by a scan.
     */
    private function _tagBrokenLinks(int $scanId): int
    {
        return LinkRecord::updateAll(
            ['lastScanId' => $scanId],
            ['status' => LinkStatus::BROKEN_STATUSES, 'isIgnored' => false],
        );
    }

    /**
     * @return array{links: array<array{url: string, source: array}>, entries: array<array{entryId: int, siteId: int}>, spidered: bool}
     */
    private function _discoverLinks(string $type, ?int $entryId, ?int $siteId, ?array $sectionIds, ?DateTime $changedSince, Settings $settings, callable $progressCallback): array
    {
        $plugin = Plugin::getInstance();
        $allLinks = [];
        $entries = [];
        $spidered = false;

        if ($type === ScanType::ENTRY && $entryId) {
            $query = Entry::find()->id($entryId)->status(null);
            if ($siteId) {
                $query->siteId($siteId);
            }
            $entry = $query->one();
            if ($entry) {
                $links = $plugin->linkExtractor->extractFromEntry($entry);
                $entries = $plugin->linkExtractor->getProcessedEntries();
                foreach ($links as $link) {
                    $allLinks[] = $this->_databaseSource($link);
                }
            }
        } elseif (in_array($type, [ScanType::FULL, ScanType::DATABASE, ScanType::SECTION, ScanType::INCREMENTAL], true)) {
            $links = $plugin->linkExtractor->extractAllLinks(
                fn($count) => $progressCallback("Extracted links from {$count} entries...", 0),
                $type === ScanType::SECTION ? $sectionIds : null,
                $type === ScanType::INCREMENTAL ? $changedSince : null,
            );
            $entries = $plugin->linkExtractor->getProcessedEntries();
            foreach ($links as $link) {
                $allLinks[] = $this->_databaseSource($link);
            }
        }

        if ($type === ScanType::FULL && $settings->spiderEnabled) {
            $progressCallback('Spidering site pages...', 0);

            $spiderLinks = $plugin->spider->crawl(
                fn($visited, $max) => $progressCallback("Spidered {$visited} of {$max} pages...", $visited / max(1, $max)),
            );
            $spidered = true;

            foreach ($spiderLinks as $link) {
                $allLinks[] = [
                    'url' => $link['url'],
                    'source' => [
                        'sourceUrl' => $link['sourceUrl'],
                        'linkText' => $link['linkText'],
                        'sourceType' => 'spider',
                    ],
                ];
            }
        }

        return ['links' => $allLinks, 'entries' => $entries, 'spidered' => $spidered];
    }

    /**
     * @param array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null} $link
     * @return array{url: string, source: array}
     */
    private function _databaseSource(array $link): array
    {
        return [
            'url' => $link['url'],
            'source' => [
                'entryId' => $link['entryId'],
                'siteId' => $link['siteId'],
                'fieldHandle' => $link['fieldHandle'],
                'linkText' => $link['linkText'],
                'sourceType' => 'database',
            ],
        ];
    }

    /**
     * Replace the sources of everything this scan read with what it found, store any new links,
     * and drop links that are no longer found anywhere.
     *
     * Sources are replaced rather than appended, so a link removed from an entry stops being
     * reported against it, and a link removed from every entry leaves the dashboard. Links someone
     * ignored are kept, so the decision survives the link coming back.
     *
     * @param int[]|null $sectionIds
     * @param array{links: array<array{url: string, source: array}>, entries: array<array{entryId: int, siteId: int}>, spidered: bool} $discovery
     * @return int How many distinct links the scan found.
     */
    private function _storeLinks(string $type, ?array $sectionIds, array $discovery, int $scanId, Settings $settings): int
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());
        $linkIds = [];

        $transaction = $db->beginTransaction();

        try {
            // Clear the sources this scan is authoritative for
            if (in_array($type, [ScanType::FULL, ScanType::DATABASE], true)) {
                $db->createCommand()->delete('{{%appleseed_link_sources}}', ['sourceType' => 'database'])->execute();
            } else {
                $bySite = [];
                foreach ($discovery['entries'] as $entry) {
                    $bySite[$entry['siteId']][] = $entry['entryId'];
                }
                foreach ($bySite as $entrySiteId => $entryIds) {
                    foreach (array_chunk($entryIds, 500) as $chunk) {
                        $db->createCommand()->delete('{{%appleseed_link_sources}}', [
                            'sourceType' => 'database',
                            'siteId' => $entrySiteId,
                            'entryId' => $chunk,
                        ])->execute();
                    }
                }
            }

            // A crawl replaces every spider source. A full scan with the spider turned off clears
            // them too, so pages crawled while it was on stop being reported.
            if ($discovery['spidered'] || ($type === ScanType::FULL && !$settings->spiderEnabled)) {
                $db->createCommand()->delete('{{%appleseed_link_sources}}', ['sourceType' => 'spider'])->execute();
            }

            $sourceRows = [];
            $seenSources = [];

            foreach ($discovery['links'] as $discovered) {
                $url = $discovered['url'];
                $urlHash = LinkUrl::hash($url);

                if (!isset($linkIds[$urlHash])) {
                    $linkIds[$urlHash] = $this->_findOrCreateLinkId($url, $urlHash, $scanId);
                }

                $source = $discovered['source'];
                $row = [
                    $linkIds[$urlHash],
                    $source['entryId'] ?? null,
                    $source['siteId'] ?? null,
                    $source['fieldHandle'] ?? null,
                    LinkText::normalize($source['linkText'] ?? null),
                    $source['sourceType'],
                    $source['sourceUrl'] ?? null,
                ];

                // The same link twice in one field is one source
                $key = implode("\0", array_map(fn($v) => (string)$v, [$row[0], $row[1], $row[2], $row[3], $row[5], $row[6]]));
                if (isset($seenSources[$key])) {
                    continue;
                }
                $seenSources[$key] = true;

                $sourceRows[] = [...$row, $now, $now, \craft\helpers\StringHelper::UUID()];

                if (count($sourceRows) >= 500) {
                    $this->_insertSources($sourceRows);
                    $sourceRows = [];
                }
            }

            if ($sourceRows !== []) {
                $this->_insertSources($sourceRows);
            }

            // Links found nowhere any more
            $db->createCommand()->delete('{{%appleseed_links}}', [
                'and',
                ['isIgnored' => false],
                ['not in', 'id', (new Query())->select(['linkId'])->from('{{%appleseed_link_sources}}')],
            ])->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return count($linkIds);
    }

    private function _findOrCreateLinkId(string $url, string $urlHash, int $scanId): int
    {
        /** @var LinkRecord|null $linkRecord */
        $linkRecord = LinkRecord::find()->where(['urlHash' => $urlHash])->one();

        if (!$linkRecord) {
            $linkRecord = new LinkRecord();
            $linkRecord->url = $url;
            $linkRecord->urlHash = $urlHash;
            $linkRecord->status = LinkStatus::UNKNOWN;
        }

        $linkRecord->host = LinkUrl::host($url);
        $linkRecord->lastScanId = $scanId;
        $linkRecord->save(false);

        return (int)$linkRecord->id;
    }

    /**
     * @param array<array<mixed>> $rows
     */
    private function _insertSources(array $rows): void
    {
        Craft::$app->getDb()->createCommand()->batchInsert(
            '{{%appleseed_link_sources}}',
            ['linkId', 'entryId', 'siteId', 'fieldHandle', 'linkText', 'sourceType', 'sourceUrl', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        )->execute();
    }
}
