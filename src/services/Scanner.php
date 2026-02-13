<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\LinkSourceRecord;
use justinholtweb\appleseed\records\ScanRecord;

class Scanner extends Component
{
    /**
     * Run a full scan: discover links, check them, report results.
     */
    public function runFullScan(?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScan('full', null, null, $progressCallback);
    }

    /**
     * Run a database-only scan.
     */
    public function runDatabaseScan(?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScan('database', null, null, $progressCallback);
    }

    /**
     * Run a scan for a single entry.
     */
    public function runEntryScan(int $entryId, ?int $siteId = null, ?callable $progressCallback = null): ScanRecord
    {
        return $this->_runScan('entry', $entryId, $siteId, $progressCallback);
    }

    private function _runScan(string $type, ?int $entryId, ?int $siteId, ?callable $progressCallback): ScanRecord
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        // Phase 0: Create scan record
        $scan = new ScanRecord();
        $scan->status = 'running';
        $scan->type = $type;
        $scan->startedAt = Db::prepareDateForDb(new \DateTime());
        $scan->entryId = $entryId;
        $scan->save(false);

        try {
            // Phase 1: Discovery
            if ($progressCallback) {
                $progressCallback('Discovering links...', 0);
            }

            $discoveredLinks = $this->_discoverLinks($type, $entryId, $siteId, $settings, $progressCallback);
            $linkRecords = $this->_upsertLinks($discoveredLinks, $scan->id);

            $scan->totalLinksFound = count($linkRecords);
            $scan->save(false);

            // Phase 2: Check links
            if ($progressCallback) {
                $progressCallback('Checking links...', 0);
            }

            $checked = 0;
            $broken = 0;
            $redirects = 0;
            $working = 0;

            foreach ($linkRecords as $linkRecord) {
                if ($linkRecord->isIgnored) {
                    continue;
                }

                // Skip external links if disabled
                if (!$settings->checkExternalLinks && $this->_isExternalUrl($linkRecord->url)) {
                    continue;
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

                $checked++;

                match ($result->status) {
                    'broken', 'dns_error', 'timeout' => $broken++,
                    'redirect' => $redirects++,
                    'server_error' => $broken++,
                    default => $working++,
                };

                if ($progressCallback) {
                    $progressCallback("Checked {$checked} of {$scan->totalLinksFound} links", $checked / max(1, $scan->totalLinksFound));
                }
            }

            // Phase 3: Finalize
            $scan->totalLinksChecked = $checked;
            $scan->brokenCount = $broken;
            $scan->redirectCount = $redirects;
            $scan->workingCount = $working;
            $scan->status = 'completed';
            $scan->completedAt = Db::prepareDateForDb(new \DateTime());
            $scan->save(false);

            // Send notification if threshold met
            if ($broken >= $settings->notificationThreshold) {
                $plugin->reporting->sendScanNotification($scan);
            }
        } catch (\Throwable $e) {
            $scan->status = 'failed';
            $scan->completedAt = Db::prepareDateForDb(new \DateTime());
            $scan->save(false);

            Craft::error("Appleseed scan failed: {$e->getMessage()}", __METHOD__);
            throw $e;
        }

        return $scan;
    }

    /**
     * @return array<array{url: string, sources: array}>
     */
    private function _discoverLinks(string $type, ?int $entryId, ?int $siteId, Settings $settings, ?callable $progressCallback): array
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
            if (in_array($type, ['full', 'database'])) {
                $links = $plugin->linkExtractor->extractAllLinks(
                    $progressCallback ? fn($count) => $progressCallback("Extracted links from {$count} entries...", 0) : null,
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
            if (in_array($type, ['full', 'spider']) && $settings->spiderEnabled) {
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
