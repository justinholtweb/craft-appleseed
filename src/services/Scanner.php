<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use Carbon\CarbonInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Query;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JustinHoltWeb\Appleseed\Data\CheckResult;
use JustinHoltWeb\Appleseed\Database\Tables;
use JustinHoltWeb\Appleseed\Events\LinkBroke;
use JustinHoltWeb\Appleseed\Events\ScanCompleted;
use JustinHoltWeb\Appleseed\Jobs\CheckLinkChunk;
use JustinHoltWeb\Appleseed\Jobs\CheckLinks;
use JustinHoltWeb\Appleseed\Jobs\ScanEntry;
use JustinHoltWeb\Appleseed\Jobs\StartScan;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\LinkSource;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\LinkText;
use JustinHoltWeb\Appleseed\Support\LinkUrl;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Throwable;

/**
 * Runs scans: discover links (entry fields, and the spider on full scans), store them, check them,
 * tally the results.
 *
 * Queued scans discover links in {@see StartScan}, then check them as a `Bus::batch()` of
 * {@see CheckLinkChunk} jobs (`scanBatchSize` links each), which several workers can share; the
 * batch's `finally` callback finalises the scan. Console scans do the same work in-process.
 *
 * One site scan at a time: an atomic cache lock (`appleseed:scan`) is taken when a scan is queued
 * or started and released when it finishes, fails or is cancelled. Entry scans (after a save) don't
 * take it, as in Appleseed 5: they only touch one entry's links.
 */
class Scanner
{
    public const string LOCK_KEY = 'appleseed:scan';

    /** Set while a queued scan waits for a worker, so the dashboard can say so. */
    public const string QUEUED_KEY = 'appleseed:scan:queued';

    /** Longer than any reasonable scan; a scan nobody touches for ABANDONED_AFTER_SECONDS is failed sooner. */
    public const int LOCK_TTL = 21600;

    /** A running scan that hasn't been touched for this long is treated as abandoned. */
    public const int ABANDONED_AFTER_SECONDS = 7200;

    /** Sources are written in chunks this size. */
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private readonly Settings $settings,
        private readonly LinkExtractor $extractor,
        private readonly Spider $spider,
        private readonly LinkChecker $checker,
    ) {}

    // Starting scans ----------------------------------------------------------------------------

    /**
     * Queue a scan. Returns false when one is already running or queued.
     *
     * @param  string  $type  One of {@see ScanType::STARTABLE}.
     * @param  list<int>|null  $sectionIds  Scan just these sections (any type but `recheck`).
     */
    public function queueScan(string $type = ScanType::FULL, ?array $sectionIds = null): bool
    {
        $lockOwner = $this->acquireLock();

        if ($lockOwner === null) {
            return false;
        }

        $sectionIds = $sectionIds !== null && $sectionIds !== [] ? array_map('intval', $sectionIds) : null;
        Cache::put(self::QUEUED_KEY, time(), self::LOCK_TTL);
        StartScan::dispatch(in_array($type, ScanType::STARTABLE, true) ? $type : ScanType::FULL, $sectionIds, $lockOwner);

        return true;
    }

    /**
     * Queue a scan of one entry. Doesn't wait for, or block, site scans.
     */
    public function queueEntryScan(int $entryId, ?int $siteId = null): void
    {
        ScanEntry::dispatch($entryId, $siteId);
    }

    /**
     * Queue a re-check of particular links.
     *
     * @param  list<int>  $linkIds
     */
    public function queueLinkChecks(array $linkIds): void
    {
        $linkIds = array_values(array_unique(array_map('intval', $linkIds)));

        if ($linkIds !== []) {
            CheckLinks::dispatch($linkIds);
        }
    }

    /**
     * Run a scan of any type start-to-finish in this process (the console, and entry scans).
     *
     * @param  list<int>|null  $sectionIds
     * @param  (callable(string, float): void)|null  $progress
     *
     * @throws ScanAlreadyRunning
     */
    public function runScan(string $type, ?int $entryId = null, ?int $siteId = null, ?array $sectionIds = null, ?callable $progress = null): Scan
    {
        $lockOwner = null;

        if ($type !== ScanType::ENTRY && ($lockOwner = $this->acquireLock()) === null) {
            throw new ScanAlreadyRunning;
        }

        try {
            $scan = $this->startScan($type, $entryId, $siteId, $sectionIds, $progress);

            try {
                if ($progress !== null) {
                    $progress('Checking links...', 0);
                }
                $ids = $this->scanLinkIds($scan->id);
                $done = 0;

                foreach (array_chunk($ids, max(1, $this->settings->scanBatchSize)) as $chunk) {
                    if (! $this->isScanRunning($scan->id)) {
                        return $scan->refresh();
                    }

                    $this->checkChunk($scan, $chunk);
                    $done += count($chunk);
                    $this->updateScanProgress($scan);
                    if ($progress !== null) {
                        $progress("Checked {$done} of ".count($ids).' links', $done / max(1, count($ids)));
                    }
                }

                $this->finalizeScan($scan);
            } catch (Throwable $e) {
                $this->failScan($scan);
                Log::channel(Plugin::LOG_CHANNEL)->error("Scan #{$scan->id} failed: {$e->getMessage()}", ['exception' => $e]);

                throw $e;
            }

            return $scan->refresh();
        } finally {
            if ($lockOwner !== null) {
                $this->releaseLock($lockOwner);
            }
        }
    }

    /**
     * Begin a scan: create it, then discover and store its links.
     *
     * The returned scan is `running`, with its links stored (tagged with `lastScanId`) but not
     * checked yet. Callers finish it in-process ({@see runScan()}) or as a batch ({@see StartScan}).
     *
     * @param  list<int>|null  $sectionIds
     * @param  (callable(string, float): void)|null  $progress
     */
    public function startScan(string $type, ?int $entryId = null, ?int $siteId = null, ?array $sectionIds = null, ?callable $progress = null): Scan
    {
        // An incremental scan needs something to be incremental to. Before the first completed
        // content scan, it reads every entry.
        $changedSince = null;

        if ($type === ScanType::INCREMENTAL) {
            $changedSince = $this->lastContentScanStart();

            if ($changedSince === null) {
                $type = ScanType::DATABASE;
            }
        }

        if ($type !== ScanType::ENTRY) {
            Cache::forget(self::QUEUED_KEY);
        }

        $scan = new Scan([
            'status' => Scan::STATUS_RUNNING,
            'type' => $type,
            'startedAt' => now(),
            'entryId' => $entryId,
        ]);
        $scan->save();

        // Keep the scan looking alive while discovery runs, so a long crawl isn't mistaken for an
        // abandoned scan.
        $lastTouch = time();
        $touching = function (string $message, float $fraction) use ($scan, $progress, &$lastTouch): void {
            if (time() - $lastTouch >= 60) {
                $lastTouch = time();
                $this->touchScan($scan);
            }

            if ($progress !== null) {
                $progress($message, $fraction);
            }
        };

        try {
            $touching('Discovering links...', 0);

            if ($type === ScanType::RECHECK) {
                $found = $this->tagBrokenLinks($scan->id);
            } else {
                $discovery = $this->discoverLinks($type, $entryId, $siteId, $sectionIds, $changedSince, $touching);
                $found = $this->storeLinks($type, $discovery, $scan->id);
            }

            $scan->totalLinksFound = $found;
            $scan->save();
        } catch (Throwable $e) {
            $this->failScan($scan);
            Log::channel(Plugin::LOG_CHANNEL)->error("Scan #{$scan->id} discovery failed: {$e->getMessage()}", ['exception' => $e]);

            throw $e;
        }

        return $scan;
    }

    /**
     * Check a started scan's links as a batch of queued jobs. The batch's `finally` callback
     * finalises the scan and releases the lock.
     */
    public function dispatchChecks(Scan $scan, ?string $lockOwner): ?Batch
    {
        $ids = $this->scanLinkIds($scan->id);

        if ($ids === []) {
            $this->finalizeScan($scan);
            $this->releaseLock($lockOwner);

            return null;
        }

        $scanId = $scan->id;
        $jobs = array_map(
            fn (array $chunk) => new CheckLinkChunk($scanId, $chunk),
            array_chunk($ids, max(1, $this->settings->scanBatchSize)),
        );

        $batch = Bus::batch($jobs)
            ->name("Appleseed scan #{$scanId}")
            ->allowFailures()
            ->finally(static function () use ($scanId, $lockOwner): void {
                app(Scanner::class)->finishBatchedScan($scanId, $lockOwner);
            })
            ->dispatch();

        Cache::put(self::batchKey($scanId), $batch->id, self::LOCK_TTL);

        return $batch;
    }

    /**
     * The batch callback: tally and complete the scan (unless it was cancelled) and release the lock.
     */
    public function finishBatchedScan(int $scanId, ?string $lockOwner): void
    {
        try {
            $scan = Scan::query()->find($scanId);

            if ($scan instanceof Scan) {
                $this->finalizeScan($scan);
            }
        } finally {
            Cache::forget(self::batchKey($scanId));
            $this->releaseLock($lockOwner);
        }
    }

    // Checking --------------------------------------------------------------------------------

    /**
     * The IDs of a scan's links that still need checking, in a stable order.
     *
     * @return list<int>
     */
    public function scanLinkIds(int $scanId): array
    {
        return Link::query()
            ->where('lastScanId', $scanId)
            ->where('isIgnored', false)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Check some of a scan's links, several hosts at once, and store the results. Ignored links,
     * and external links when external checking is off, are skipped without touching the network.
     *
     * @param  list<int>  $linkIds
     */
    public function checkChunk(Scan $scan, array $linkIds): void
    {
        $links = Link::query()
            ->whereIn('id', $linkIds)
            ->where('isIgnored', false)
            ->orderBy('id')
            ->get()
            ->reject(fn (Link $link) => ! $this->settings->checkExternalLinks && LinkUrl::isExternal($link->url));

        if ($links->isEmpty()) {
            return;
        }

        $results = $this->checker->checkMany($links->pluck('url')->map(fn (mixed $url) => (string) $url)->values()->all());

        foreach ($links as $link) {
            if (isset($results[$link->url])) {
                $this->applyResult($link, $results[$link->url], $scan->id);
            }
        }
    }

    /**
     * Check one link right now, outside of any scan, and store the result.
     */
    public function checkLink(Link $link): CheckResult
    {
        $result = $this->checker->checkUrl($link->url);
        $this->applyResult($link, $result);

        return $result;
    }

    /**
     * Store a check result on a link, keeping track of when it started failing. A link that's newly
     * broken dispatches {@see LinkBroke}.
     */
    public function applyResult(Link $link, CheckResult $result, ?int $scanId = null): void
    {
        $wasBroken = LinkStatus::isBroken($link->status);
        $now = now();

        if ($result->isBroken()) {
            if (! $wasBroken || $link->firstBrokenAt === null) {
                $link->firstBrokenAt = $now;
            }
        } else {
            $link->firstBrokenAt = null;
        }

        $link->statusCode = $result->statusCode;
        $link->status = $result->status;
        $link->redirectUrl = $result->redirectUrl;
        $link->redirectChain = $result->redirectChain;
        $link->errorMessage = $result->errorMessage;
        $link->lastCheckedAt = $now;

        if ($scanId !== null) {
            $link->lastScanId = $scanId;
        }

        $link->save();

        if ($result->isBroken() && ! $wasBroken) {
            event(new LinkBroke($link, $result, $scanId));
        }
    }

    // Scan state ------------------------------------------------------------------------------

    /**
     * Refresh a running scan's tallies from the database (best-effort progress).
     */
    public function updateScanProgress(Scan $scan): void
    {
        $this->applyCounts($scan);
        $scan->save();
        $this->touchScan($scan);
    }

    /**
     * Mark a running scan as alive, so it isn't taken for abandoned.
     */
    public function touchScan(Scan $scan): void
    {
        Scan::query()->whereKey($scan->id)->update(['dateUpdated' => Query::prepareDateForDb(now())]);
    }

    /**
     * Whether a scan is still running: not finished, failed or cancelled.
     */
    public function isScanRunning(int $scanId): bool
    {
        return Scan::query()->whereKey($scanId)->running()->exists();
    }

    /**
     * Tally a scan, mark it completed and dispatch {@see ScanCompleted}. A scan that was cancelled
     * or failed meanwhile is left as it is. Returns whether the scan completed.
     */
    public function finalizeScan(Scan $scan): bool
    {
        $scan->refresh();

        if (! $scan->isRunning()) {
            return false;
        }

        $this->applyCounts($scan);
        $scan->status = Scan::STATUS_COMPLETED;
        $scan->completedAt = now();
        $scan->save();

        event(new ScanCompleted($scan));

        return true;
    }

    public function failScan(Scan $scan): void
    {
        $scan->status = Scan::STATUS_FAILED;
        $scan->completedAt = now();
        $scan->save();
    }

    /**
     * Stop every running scan: its batch is cancelled, so queued chunks skip their links, and the
     * scan lock is released. Results already stored are kept.
     *
     * @return int How many scans were cancelled.
     */
    public function cancelRunningScans(): int
    {
        $running = Scan::query()->running()->where('type', '!=', ScanType::ENTRY)->pluck('id');

        foreach ($running as $scanId) {
            $batchId = Cache::pull(self::batchKey((int) $scanId));

            if (is_string($batchId)) {
                Bus::findBatch($batchId)?->cancel();
            }
        }

        $cancelled = Scan::query()->running()->update([
            'status' => Scan::STATUS_CANCELLED,
            'completedAt' => Query::prepareDateForDb(now()),
            'dateUpdated' => Query::prepareDateForDb(now()),
        ]);

        // A queued scan that hasn't started yet holds the lock too.
        Cache::lock(self::LOCK_KEY)->forceRelease();
        Cache::forget(self::QUEUED_KEY);

        return $cancelled;
    }

    /**
     * Mark running scans nobody has touched for {@see ABANDONED_AFTER_SECONDS} as failed (their
     * queue job died), so they stop blocking the schedule and the dashboard's progress bar.
     */
    public function failAbandonedScans(): void
    {
        $failed = Scan::query()
            ->running()
            ->where('dateUpdated', '<', Query::prepareDateForDb(now()->subSeconds(self::ABANDONED_AFTER_SECONDS)))
            ->update([
                'status' => Scan::STATUS_FAILED,
                'completedAt' => Query::prepareDateForDb(now()),
            ]);

        if ($failed > 0 && ! Scan::query()->running()->exists()) {
            Cache::lock(self::LOCK_KEY)->forceRelease();
        }
    }

    /**
     * Delete finished scans older than `$days` days. Links and their results are kept.
     *
     * @return int How many scans were deleted.
     */
    public function pruneScans(int $days): int
    {
        return Scan::query()
            ->where('status', '!=', Scan::STATUS_RUNNING)
            ->where('dateCreated', '<', Query::prepareDateForDb(now()->subDays(max(1, $days))))
            ->delete();
    }

    // Ignoring ----------------------------------------------------------------------------------

    /**
     * Ignore a link: it's no longer checked, counted or reported, and the decision survives scans.
     */
    public function ignoreLink(Link $link, ?int $userId = null): void
    {
        $link->isIgnored = true;
        $link->status = LinkStatus::IGNORED;
        $link->firstBrokenAt = null;
        $link->ignoredById = $userId;
        $link->dateIgnored = now();
        $link->save();
    }

    /**
     * Stop ignoring a link. It's checked again on the next scan.
     */
    public function unignoreLink(Link $link): void
    {
        $link->isIgnored = false;
        $link->status = LinkStatus::UNKNOWN;
        $link->ignoredById = null;
        $link->dateIgnored = null;
        $link->save();
    }

    // The scan lock -----------------------------------------------------------------------------

    /**
     * Takes the scan lock, returning its owner token, or null when a site scan is running or
     * queued. Running scans whose job died are failed first, so they can't hold things up.
     */
    public function acquireLock(): ?string
    {
        $this->failAbandonedScans();

        if (Scan::query()->running()->where('type', '!=', ScanType::ENTRY)->exists()) {
            return null;
        }

        $owner = Str::uuid()->toString();
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL, $owner);

        return $lock->get() ? $owner : null;
    }

    /**
     * Whether a site scan is queued and waiting for a worker.
     */
    public function isQueued(): bool
    {
        return Cache::has(self::QUEUED_KEY);
    }

    public function releaseLock(?string $owner): void
    {
        if ($owner !== null) {
            Cache::forget(self::QUEUED_KEY);
            Cache::restoreLock(self::LOCK_KEY, $owner)->release();
        }
    }

    public function lock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
    }

    /**
     * The batch checking a scan's links, while it runs.
     */
    public function batchFor(int $scanId): ?Batch
    {
        $batchId = Cache::get(self::batchKey($scanId));

        return is_string($batchId) ? Bus::findBatch($batchId) : null;
    }

    private static function batchKey(int $scanId): string
    {
        return "appleseed:scan:{$scanId}:batch";
    }

    // Discovery ---------------------------------------------------------------------------------

    /**
     * Recompute a scan's status tallies from its stored links.
     */
    private function applyCounts(Scan $scan): void
    {
        $byStatus = Link::query()
            ->where('lastScanId', $scan->id)
            ->where('isIgnored', false)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count) => (int) $count);

        $broken = 0;

        foreach (LinkStatus::BROKEN_STATUSES as $status) {
            $broken += (int) ($byStatus[$status] ?? 0);
        }

        $scan->brokenCount = $broken;
        $scan->redirectCount = (int) ($byStatus[LinkStatus::REDIRECT] ?? 0);
        $scan->workingCount = (int) ($byStatus[LinkStatus::WORKING] ?? 0);
        $scan->totalLinksChecked = (int) $byStatus->sum() - (int) ($byStatus[LinkStatus::UNKNOWN] ?? 0);
        $scan->newBrokenCount = $scan->startedAt === null ? 0 : Link::query()
            ->where('lastScanId', $scan->id)
            ->broken()
            ->where('firstBrokenAt', '>=', Query::prepareDateForDb($scan->startedAt))
            ->count();
    }

    /**
     * When the last completed scan that read every entry's content started: what an incremental
     * scan reads changes since.
     */
    private function lastContentScanStart(): ?CarbonInterface
    {
        $scan = Scan::query()->completed()->readContent()->orderByDesc('startedAt')->first();

        return $scan?->startedAt;
    }

    /**
     * Tag every broken link for re-checking by a scan.
     */
    private function tagBrokenLinks(int $scanId): int
    {
        return Link::query()->broken()->update(['lastScanId' => $scanId]);
    }

    /**
     * @param  list<int>|null  $sectionIds
     * @param  callable(string, float): void  $progress
     * @return array{links: list<array{url: string, source: array<string, mixed>}>, entries: list<array{entryId: int, siteId: int}>, spidered: bool}
     */
    private function discoverLinks(string $type, ?int $entryId, ?int $siteId, ?array $sectionIds, ?CarbonInterface $changedSince, callable $progress): array
    {
        $links = [];
        $entries = [];
        $spidered = false;

        if ($type === ScanType::ENTRY && $entryId) {
            $entry = Entry::find()->id($entryId)->siteId($siteId ?? '*')->status(null)->one();

            if ($entry instanceof Entry) {
                foreach ($this->extractor->extractFromEntry($entry) as $link) {
                    $links[] = self::databaseSource($link);
                }

                $entries = $this->extractor->getProcessedEntries();
            }
        } elseif (in_array($type, [ScanType::FULL, ScanType::DATABASE, ScanType::SECTION, ScanType::INCREMENTAL], true)) {
            $found = $this->extractor->extractAllLinks(
                fn (int $count) => $progress("Extracted links from {$count} entries...", 0),
                $type === ScanType::SECTION ? $sectionIds : null,
                $type === ScanType::INCREMENTAL ? $changedSince : null,
            );

            foreach ($found as $link) {
                $links[] = self::databaseSource($link);
            }

            $entries = $this->extractor->getProcessedEntries();
        }

        if ($type === ScanType::FULL && $this->settings->spiderEnabled) {
            $progress('Spidering site pages...', 0);

            $crawled = $this->spider->crawl(
                fn (int $visited, int $max) => $progress("Spidered {$visited} of {$max} pages...", $visited / max(1, $max)),
            );
            $spidered = true;

            foreach ($crawled as $link) {
                $links[] = [
                    'url' => $link['url'],
                    'source' => [
                        'sourceUrl' => $link['sourceUrl'],
                        'linkText' => $link['linkText'],
                        'sourceType' => LinkSource::TYPE_SPIDER,
                    ],
                ];
            }
        }

        return ['links' => $links, 'entries' => $entries, 'spidered' => $spidered];
    }

    /**
     * @param  array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}  $link
     * @return array{url: string, source: array<string, mixed>}
     */
    private static function databaseSource(array $link): array
    {
        return [
            'url' => $link['url'],
            'source' => [
                'entryId' => $link['entryId'],
                'siteId' => $link['siteId'],
                'fieldHandle' => $link['fieldHandle'],
                'linkText' => $link['linkText'],
                'sourceType' => LinkSource::TYPE_DATABASE,
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
     * @param  array{links: list<array{url: string, source: array<string, mixed>}>, entries: list<array{entryId: int, siteId: int}>, spidered: bool}  $discovery
     * @return int How many distinct links the scan found.
     */
    private function storeLinks(string $type, array $discovery, int $scanId): int
    {
        return DB::transaction(function () use ($type, $discovery, $scanId): int {
            $sources = DB::table(Tables::LINK_SOURCES);

            // Clear the sources this scan is authoritative for
            if (in_array($type, [ScanType::FULL, ScanType::DATABASE], true)) {
                (clone $sources)->where('sourceType', LinkSource::TYPE_DATABASE)->delete();
            } else {
                $bySite = [];

                foreach ($discovery['entries'] as $entry) {
                    $bySite[$entry['siteId']][] = $entry['entryId'];
                }

                foreach ($bySite as $entrySiteId => $entryIds) {
                    foreach (array_chunk($entryIds, self::INSERT_CHUNK) as $chunk) {
                        (clone $sources)
                            ->where('sourceType', LinkSource::TYPE_DATABASE)
                            ->where('siteId', $entrySiteId)
                            ->whereIn('entryId', $chunk)
                            ->delete();
                    }
                }
            }

            // A crawl replaces every spider source. A full scan with the spider turned off clears
            // them too, so pages crawled while it was on stop being reported.
            if ($discovery['spidered'] || ($type === ScanType::FULL && ! $this->settings->spiderEnabled)) {
                (clone $sources)->where('sourceType', LinkSource::TYPE_SPIDER)->delete();
            }

            $now = Query::prepareDateForDb(now());
            $linkIds = [];
            $rows = [];
            $seen = [];

            foreach ($discovery['links'] as $discovered) {
                $url = $discovered['url'];
                $hash = LinkUrl::hash($url);
                $linkIds[$hash] ??= $this->findOrCreateLinkId($url, $hash, $scanId);

                $source = $discovered['source'];
                $row = [
                    'linkId' => $linkIds[$hash],
                    'entryId' => $source['entryId'] ?? null,
                    'siteId' => $source['siteId'] ?? null,
                    'fieldHandle' => $source['fieldHandle'] ?? null,
                    'linkText' => LinkText::normalize(isset($source['linkText']) ? (string) $source['linkText'] : null),
                    'sourceType' => $source['sourceType'],
                    'sourceUrl' => $source['sourceUrl'] ?? null,
                ];

                // The same link twice in one field (or on one page) is one source
                $key = implode("\0", array_map(fn (mixed $v) => (string) $v, [$row['linkId'], $row['entryId'], $row['siteId'], $row['fieldHandle'], $row['sourceType'], $row['sourceUrl']]));

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = $row + ['dateCreated' => $now, 'dateUpdated' => $now, 'uid' => Str::uuid()->toString()];

                if (count($rows) >= self::INSERT_CHUNK) {
                    DB::table(Tables::LINK_SOURCES)->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table(Tables::LINK_SOURCES)->insert($rows);
            }

            // Links found nowhere any more
            Link::query()
                ->where('isIgnored', false)
                ->whereNotIn('id', DB::table(Tables::LINK_SOURCES)->select('linkId'))
                ->delete();

            return count($linkIds);
        });
    }

    private function findOrCreateLinkId(string $url, string $hash, int $scanId): int
    {
        $link = Link::query()->where('urlHash', $hash)->first() ?? new Link([
            'url' => $url,
            'urlHash' => $hash,
            'status' => LinkStatus::UNKNOWN,
        ]);

        $link->host = LinkUrl::host($url);
        $link->lastScanId = $scanId;
        $link->save();

        return $link->id;
    }
}
