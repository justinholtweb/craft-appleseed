<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\db\ActiveQuery;
use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\LinkSourceRecord;
use justinholtweb\appleseed\records\ScanRecord;

class Scanner extends Component
{
    /** @var string[] Link statuses that count as broken. */
    private const BROKEN_STATUSES = ['broken', 'dns_error', 'timeout', 'server_error'];

    /**
     * Run a full scan synchronously: discover links, check them, report results.
     */
    public function runFullScan(?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScanSync('full', null, null, null, $progressCallback);
    }

    /**
     * Run a database-only scan synchronously.
     */
    public function runDatabaseScan(?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScanSync('database', null, null, null, $progressCallback);
    }

    /**
     * Run a scan for a single entry synchronously.
     */
    public function runEntryScan(int $entryId, ?int $siteId = null, ?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScanSync('entry', $entryId, $siteId, null, $progressCallback);
    }

    /**
     * Run a scan limited to specific sections synchronously.
     *
     * @param int[] $sectionIds
     */
    public function runSectionScan(array $sectionIds, ?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScanSync('section', null, null, $sectionIds, $progressCallback);
    }

    /**
     * Begin a scan: create the scan record and discover + persist its links.
     *
     * The returned scan is left in the `running` state with its links stored
     * (tagged with `lastScanId`) but not yet checked. Callers finish the scan
     * either synchronously (see {@see _runScanSync()}) or in batches via the
     * queue (see the CheckLinksBatchedJob).
     *
     * @param int[]|null $sectionIds
     */
    public function startScan(string $type, ?int $entryId = null, ?int $siteId = null, ?array $sectionIds = null, ?callable $progressCallback = null): ScanRecord
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $scan = new ScanRecord();
        $scan->status = 'running';
        $scan->type = $type;
        $scan->startedAt = Db::prepareDateForDb(new \DateTime());
        $scan->entryId = $entryId;
        $scan->save(false);

        try {
            if ($progressCallback) {
                $progressCallback('Discovering links...', 0);
            }

            $discoveredLinks = $this->_discoverLinks($type, $entryId, $siteId, $sectionIds, $settings, $progressCallback);
            $linkRecords = $this->_upsertLinks($discoveredLinks, $scan->id);

            $scan->totalLinksFound = count($linkRecords);
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

        if (!$settings->checkExternalLinks && $this->_isExternalUrl($linkRecord->url)) {
            return;
        }

        $result = $plugin->linkChecker->checkUrl($linkRecord->url);

        $linkRecord->statusCode = $result->statusCode;
        $linkRecord->status = $result->status;
        $linkRecord->redirectUrl = $result->redirectUrl;
        $linkRecord->redirectChain = $result->redirectChain ? json_encode($result->redirectChain) : null;
        $linkRecord->errorMessage = $result->errorMessage;
        $linkRecord->lastCheckedAt = Db::prepareDateForDb(new \DateTime());
        $linkRecord->lastScanId = $scan->id;
        $linkRecord->save(false);
    }

    /**
     * Refresh a running scan's tallies from the database (best-effort progress).
     */
    public function updateScanProgress(ScanRecord $scan): void
    {
        $this->_applyCounts($scan);
        $scan->save(false);
    }

    /**
     * Finalize a scan: tally results, mark it completed, and notify if needed.
     *
     * Counts are recomputed authoritatively from the stored link records, so
     * this is safe to call once at the end of a synchronous scan or after the
     * final batch of a queued scan.
     */
    public function finalizeScan(ScanRecord $scan): void
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $this->_applyCounts($scan);
        $scan->status = 'completed';
        $scan->completedAt = Db::prepareDateForDb(new \DateTime());
        $scan->save(false);

        if ($scan->brokenCount >= $settings->notificationThreshold) {
            $plugin->reporting->sendScanNotification($scan);
        }
    }

    /**
     * Mark a scan as failed.
     */
    public function failScan(ScanRecord $scan): void
    {
        $scan->status = 'failed';
        $scan->completedAt = Db::prepareDateForDb(new \DateTime());
        $scan->save(false);
    }

    /**
     * Run a scan start-to-finish in the current process.
     *
     * @param int[]|null $sectionIds
     */
    private function _runScanSync(string $type, ?int $entryId, ?int $siteId, ?array $sectionIds, ?callable $progressCallback): ScanRecord
    {
        $plugin = Plugin::getInstance();

        $scan = $this->startScan($type, $entryId, $siteId, $sectionIds, $progressCallback);

        try {
            if ($progressCallback) {
                $progressCallback('Checking links...', 0);
            }

            $total = $scan->totalLinksFound;
            $checked = 0;

            foreach ($this->getScanLinkQuery($scan->id)->each() as $linkRecord) {
                /** @var LinkRecord $linkRecord */
                $this->checkScanLink($linkRecord, $scan);
                $checked++;

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
     * Recompute a scan's status tallies from its stored link records.
     */
    private function _applyCounts(ScanRecord $scan): void
    {
        $broken = (int) $this->getScanLinkQuery($scan->id)
            ->andWhere(['status' => self::BROKEN_STATUSES])
            ->count();
        $redirects = (int) $this->getScanLinkQuery($scan->id)
            ->andWhere(['status' => 'redirect'])
            ->count();
        $working = (int) $this->getScanLinkQuery($scan->id)
            ->andWhere(['status' => 'working'])
            ->count();

        $scan->brokenCount = $broken;
        $scan->redirectCount = $redirects;
        $scan->workingCount = $working;
        $scan->totalLinksChecked = $broken + $redirects + $working;
    }

    /**
     * @return array<array{url: string, sources: array}>
     */
    private function _discoverLinks(string $type, ?int $entryId, ?int $siteId, ?array $sectionIds, Settings $settings, ?callable $progressCallback): array
    {
        $plugin = Plugin::getInstance();
        $allLinks = [];

        if ($type === 'entry' && $entryId) {
            // Single entry scan
            $query = Entry::find()->id($entryId)->status(null);
            if ($siteId) {
                $query->siteId($siteId);
            }
            $entry = $query->one();
            if ($entry) {
                $links = $plugin->linkExtractor->extractFromEntry($entry);
                foreach ($links as $link) {
                    $allLinks[] = [
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
            }
        } else {
            // Database scan
            if (in_array($type, ['full', 'database', 'section'], true)) {
                $links = $plugin->linkExtractor->extractAllLinks(
                    $progressCallback ? fn($count) => $progressCallback("Extracted links from {$count} entries...", 0) : null,
                    $sectionIds,
                );
                foreach ($links as $link) {
                    $allLinks[] = [
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
            }

            // Spider scan
            if (in_array($type, ['full', 'spider'], true) && $settings->spiderEnabled) {
                if ($progressCallback) {
                    $progressCallback('Spidering site pages...', 0);
                }

                $spiderLinks = $plugin->spider->crawl(
                    $progressCallback ? fn($visited, $max) => $progressCallback("Spidered {$visited} of {$max} pages...", $visited / max(1, $max)) : null,
                );

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
        }

        return $allLinks;
    }

    /**
     * Upsert discovered links into the database, creating source records.
     *
     * @return LinkRecord[]
     */
    private function _upsertLinks(array $discoveredLinks, int $scanId): array
    {
        $linkRecords = [];
        $urlMap = []; // urlHash => LinkRecord

        foreach ($discoveredLinks as $discovered) {
            $url = $discovered['url'];
            $urlHash = hash('sha256', $url);

            // Find or create link record
            if (!isset($urlMap[$urlHash])) {
                $linkRecord = LinkRecord::find()->where(['urlHash' => $urlHash])->one();
                if (!$linkRecord) {
                    $linkRecord = new LinkRecord();
                    $linkRecord->url = $url;
                    $linkRecord->urlHash = $urlHash;
                    $linkRecord->status = 'unknown';
                }
                $linkRecord->lastScanId = $scanId;
                $linkRecord->save(false);

                $urlMap[$urlHash] = $linkRecord;
                $linkRecords[] = $linkRecord;
            }

            // Create source record
            $source = new LinkSourceRecord();
            $source->linkId = $urlMap[$urlHash]->id;
            $source->entryId = $discovered['source']['entryId'] ?? null;
            $source->siteId = $discovered['source']['siteId'] ?? null;
            $source->fieldHandle = $discovered['source']['fieldHandle'] ?? null;
            $source->linkText = $discovered['source']['linkText'] ?? null;
            $source->sourceType = $discovered['source']['sourceType'];
            $source->sourceUrl = $discovered['source']['sourceUrl'] ?? null;
            $source->save(false);
        }

        return $linkRecords;
    }

    private function _isExternalUrl(string $url): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        if (!$urlHost) {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteHost = parse_url($site->getBaseUrl(), PHP_URL_HOST);
            if ($siteHost && strcasecmp($urlHost, $siteHost) === 0) {
                return false;
            }
        }

        return true;
    }
}
