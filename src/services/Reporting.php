<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Appleseed\Database\Tables;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\LinkSource;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\Presenter;
use JustinHoltWeb\Appleseed\Support\ScanType;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Reading results back: the dashboard, the widget, the entry sidebar, CSV, GraphQL, MCP and the
 * report email.
 *
 * Link listings are Eloquent queries on {@see Link} with its scopes; rows go out as arrays (the
 * link's columns plus `sources`, each enriched with its entry's title, section, status and edit
 * URL), loaded for a whole page at once rather than per link.
 */
class Reporting
{
    /**
     * Filters the link listings accept, all optional:
     *
     * - `status`: a status, `broken` for every broken status, or `ignored` for ignored links
     * - `search`: part of the URL
     * - `host`: the link's host, exactly
     * - `siteId`: only links found on this site
     * - `entryId`: only links found in this entry
     * - `includeIgnored`: with no status filter, include ignored links too
     */
    public const array FILTER_KEYS = ['status', 'search', 'host', 'siteId', 'entryId', 'includeIgnored'];

    public const array SORTS = ['url', 'status', 'statusCode', 'lastCheckedAt', 'host', 'firstBrokenAt', 'id'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Scanner $scanner,
    ) {}

    public function getBrokenLinkCount(): int
    {
        return Link::query()->broken()->count();
    }

    /**
     * @return array{total: int, broken: int, redirects: int, working: int, blocked: int, unchecked: int, ignored: int}
     */
    public function getDashboardSummary(?int $siteId = null): array
    {
        $rows = Link::query()
            ->forSite($siteId)
            ->selectRaw('status, isIgnored, COUNT(*) as aggregate')
            ->groupBy('status', 'isIgnored')
            ->toBase()
            ->get();

        $summary = ['total' => 0, 'broken' => 0, 'redirects' => 0, 'working' => 0, 'blocked' => 0, 'unchecked' => 0, 'ignored' => 0];

        foreach ($rows as $row) {
            $count = (int) $row->aggregate;

            if ((bool) $row->isIgnored) {
                $summary['ignored'] += $count;

                continue;
            }

            $summary['total'] += $count;
            $status = (string) $row->status;

            match (true) {
                LinkStatus::isBroken($status) => $summary['broken'] += $count,
                $status === LinkStatus::REDIRECT => $summary['redirects'] += $count,
                $status === LinkStatus::WORKING => $summary['working'] += $count,
                $status === LinkStatus::BLOCKED => $summary['blocked'] += $count,
                $status === LinkStatus::UNKNOWN => $summary['unchecked'] += $count,
                default => null,
            };
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters  See {@see FILTER_KEYS}.
     * @return Builder<Link>
     */
    public function linkQuery(array $filters = []): Builder
    {
        return Link::query()->filtered($filters);
    }

    /**
     * A page of links for the dashboard table.
     *
     * @param  array<string, mixed>  $filters  See {@see FILTER_KEYS}.
     * @return array{links: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function getLinkResults(array $filters, int $page = 1, int $perPage = 50, string $sort = 'status', string $direction = 'asc'): array
    {
        $query = $this->linkQuery($filters);
        $total = (clone $query)->count();
        $page = max(1, $page);

        $links = $this->sorted($query, $sort, $direction)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'links' => $this->withSources($links, isset($filters['siteId']) ? (int) $filters['siteId'] : null),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * @param  Builder<Link>  $query
     * @return Builder<Link>
     */
    public function sorted(Builder $query, string $sort, string $direction): Builder
    {
        $column = in_array($sort, self::SORTS, true) ? $sort : 'status';

        return $query->orderBy($column, strtolower($direction) === 'desc' ? 'desc' : 'asc')->orderBy('id');
    }

    /**
     * Links as arrays, each with its `sources` (entry metadata included), in two queries rather
     * than one per link.
     *
     * @param  iterable<Link>  $links
     * @param  int|null  $siteId  Only sources on this site (crawled pages count for every site).
     * @return list<array<string, mixed>>
     */
    public function withSources(iterable $links, ?int $siteId = null): array
    {
        $rows = [];

        foreach ($links as $link) {
            $rows[] = Presenter::linkData($link);
        }

        if ($rows === []) {
            return [];
        }

        $sources = LinkSource::query()
            ->whereIn('linkId', array_column($rows, 'id'))
            ->forSite($siteId)
            ->orderBy('id')
            ->get()
            ->map(fn (LinkSource $source) => $source->getAttributes() + ['entryId' => $source->entryId, 'siteId' => $source->siteId])
            ->all();

        $byLink = [];

        foreach ($this->enrichSources($sources) as $source) {
            $byLink[(int) $source['linkId']][] = $source;
        }

        foreach ($rows as &$row) {
            $row['sources'] = $byLink[(int) $row['id']] ?? [];
        }

        unset($row);

        return $rows;
    }

    /**
     * One link with its sources, its redirect chain and who ignored it.
     *
     * @return array<string, mixed>|null
     */
    public function getLinkDetail(int $linkId): ?array
    {
        $link = Link::query()->find($linkId);

        if (! $link instanceof Link) {
            return null;
        }

        [$row] = $this->withSources([$link]);
        $row['ignoredBy'] = $link->ignoredById !== null
            ? User::find()->id($link->ignoredById)->status(null)->one()
            : null;

        return $row;
    }

    public function getLinkIdByUrl(string $url): ?int
    {
        $id = Link::query()->where('urlHash', hash('sha256', trim($url)))->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * The links found in one entry, with how each fares.
     *
     * @param  bool  $problemsOnly  Only broken links, redirects and blocked links.
     * @return list<array<string, mixed>>
     */
    public function getEntryLinks(int $entryId, ?int $siteId = null, bool $problemsOnly = false): array
    {
        $byLink = [];

        LinkSource::query()
            ->where('entryId', $entryId)
            ->where('sourceType', LinkSource::TYPE_DATABASE)
            ->when($siteId, fn (Builder $q) => $q->where('siteId', $siteId))
            ->orderBy('id')
            ->get(['linkId', 'fieldHandle', 'linkText'])
            ->each(function (LinkSource $source) use (&$byLink): void {
                $byLink[$source->linkId][] = $source;
            });

        if ($byLink === []) {
            return [];
        }

        $query = Link::query()->whereIn('id', array_keys($byLink))->orderBy('status')->orderBy('url');

        if ($problemsOnly) {
            $query->notIgnored()->whereIn('status', [...LinkStatus::BROKEN_STATUSES, LinkStatus::REDIRECT, LinkStatus::BLOCKED]);
        }

        return $query->get()->map(function (Link $link) use ($byLink): array {
            $sources = $byLink[$link->id] ?? [];

            return Presenter::linkData($link) + [
                'fields' => array_values(array_unique(array_filter(array_map(fn (LinkSource $s) => $s->fieldHandle, $sources)))),
                'linkText' => ($sources[0] ?? null)?->linkText,
            ];
        })->values()->all();
    }

    /**
     * Hosts with the most broken links: usually a site that moved or went away.
     *
     * @return list<array{host: string, broken: int}>
     */
    public function getWorstHosts(int $limit = 10, ?int $siteId = null): array
    {
        return Link::query()
            ->broken()
            ->forSite($siteId)
            ->whereNotNull('host')
            ->selectRaw('host, COUNT(*) as aggregate')
            ->groupBy('host')
            ->orderByDesc('aggregate')
            ->orderBy('host')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['host' => (string) $row->host, 'broken' => (int) $row->aggregate])
            ->values()
            ->all();
    }

    /**
     * Entries with the most broken links: where fixing pays off first.
     *
     * @return list<array{entryId: int, siteId: int|null, broken: int, title: string|null, cpUrl: string|null}>
     */
    public function getWorstEntries(int $limit = 10, ?int $siteId = null): array
    {
        $rows = DB::table(Tables::LINK_SOURCES, 'ls')
            ->join(Tables::LINKS.' as l', 'l.id', '=', 'ls.linkId')
            ->whereIn('l.status', LinkStatus::BROKEN_STATUSES)
            ->where('l.isIgnored', false)
            ->whereNotNull('ls.entryId')
            ->when($siteId, fn ($q) => $q->where('ls.siteId', $siteId))
            ->selectRaw('ls.entryId as entryId, MIN(ls.siteId) as siteId, COUNT(DISTINCT l.id) as broken')
            ->groupBy('ls.entryId')
            ->orderByDesc('broken')
            ->orderBy('entryId')
            ->limit($limit)
            ->get();

        $entries = $this->loadEntries($rows->map(fn (object $row) => ['entryId' => $row->entryId, 'siteId' => $row->siteId])->all());

        return $rows->map(function (object $row) use ($entries): array {
            $entry = $entries["{$row->siteId}:{$row->entryId}"] ?? null;

            return [
                'entryId' => (int) $row->entryId,
                'siteId' => $row->siteId !== null ? (int) $row->siteId : null,
                'broken' => (int) $row->broken,
                'title' => $entry?->title,
                'cpUrl' => $entry?->getCpEditUrl(),
            ];
        })->values()->all();
    }

    /**
     * Hosts links point at, most-linked first, for the dashboard's host filter.
     *
     * @return list<string>
     */
    public function getHosts(int $limit = 200): array
    {
        return Link::query()
            ->notIgnored()
            ->whereNotNull('host')
            ->selectRaw('host, COUNT(*) as aggregate')
            ->groupBy('host')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->toBase()
            ->pluck('host')
            ->map(fn (mixed $host) => (string) $host)
            ->values()
            ->all();
    }

    /**
     * Scans, newest first.
     *
     * @return list<Scan>
     */
    public function getScans(int $limit = 20, int $offset = 0): array
    {
        return Scan::query()
            ->newestFirst()
            ->offset(max(0, $offset))
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->values()
            ->all();
    }

    public function getTotalScans(): int
    {
        return Scan::query()->count();
    }

    public function getScanById(int $id): ?Scan
    {
        return Scan::query()->find($id);
    }

    /**
     * The most recently completed scan.
     *
     * @param  list<string>|null  $types  Only scans of these types.
     */
    public function getLastCompletedScan(?array $types = null): ?Scan
    {
        return Scan::query()
            ->completed()
            ->when($types !== null, fn (Builder $q) => $q->whereIn('type', $types ?? []))
            ->orderByDesc('completedAt')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The running site scan, if any. Scans whose queue job died are marked failed first.
     */
    public function getRunningScan(): ?Scan
    {
        $this->scanner->failAbandonedScans();

        return Scan::query()->running()->where('type', '!=', ScanType::ENTRY)->orderByDesc('startedAt')->first();
    }

    /**
     * Broken links for a notification: the newest breakages first. With `$onlyNew`, only links
     * that broke during the scan.
     *
     * @return list<Link>
     */
    public function brokenLinksFor(Scan $scan, int $limit, bool $onlyNew): array
    {
        return Link::query()
            ->broken()
            ->when($onlyNew && $scan->startedAt !== null, fn (Builder $q) => $q->where('firstBrokenAt', '>=', Query::prepareDateForDb($scan->startedAt)))
            ->orderByDesc('firstBrokenAt')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->values()
            ->all();
    }

    /**
     * CSV of links, one row per place each was found.
     *
     * @param  array<string, mixed>  $filters  See {@see FILTER_KEYS}. Without a status filter every
     *                                         link is exported, ignored ones included.
     */
    public function generateCsv(array $filters = []): string
    {
        $output = fopen('php://temp', 'r+');

        if ($output === false) {
            return '';
        }

        fputcsv($output, ['URL', 'Status', 'Status Code', 'Redirect URL', 'Error', 'Last Checked', 'Broken Since', 'Ignored', 'Entry Title', 'Entry Section', 'Entry Status', 'Entry CP URL', 'Field Handle', 'Link Text', 'Source Type', 'Spider Source URL'], escape: '');

        $siteId = isset($filters['siteId']) ? (int) $filters['siteId'] : null;

        $this->linkQuery(['includeIgnored' => true] + $filters)
            ->orderBy('status')
            ->orderBy('url')
            ->orderBy('id')
            ->chunk(200, function ($links) use ($output, $siteId): void {
                foreach ($this->withSources($links, $siteId) as $link) {
                    $base = [
                        $link['url'],
                        $link['status'],
                        $link['statusCode'] ?? '',
                        $link['redirectUrl'] ?? '',
                        $link['errorMessage'] ?? '',
                        Presenter::date($link['lastCheckedAt']) ?? '',
                        Presenter::date($link['firstBrokenAt']) ?? '',
                        $link['isIgnored'] ? 'Yes' : 'No',
                    ];

                    if ($link['sources'] === []) {
                        fputcsv($output, [...$base, '', '', '', '', '', '', '', ''], escape: '');

                        continue;
                    }

                    foreach ($link['sources'] as $source) {
                        fputcsv($output, [
                            ...$base,
                            $source['entryTitle'] ?? '',
                            $source['entrySection'] ?? '',
                            $source['entryStatus'] ?? '',
                            $source['entryCpUrl'] ?? '',
                            $source['fieldHandle'] ?? '',
                            $source['linkText'] ?? '',
                            $source['sourceType'] ?? '',
                            $source['sourceUrl'] ?? '',
                        ], escape: '');
                    }
                }
            });

        rewind($output);
        $csv = (string) stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * The scan report email's HTML: the report, inside the configured layout template (a site
     * template) or Appleseed's own.
     */
    public function renderScanReportEmail(Scan $scan): string
    {
        $summary = $this->getDashboardSummary();
        $cpUrl = rtrim(cp_url(), '/');
        $brokenLinks = $this->withSources($this->brokenLinksFor($scan, 25, $this->settings->notifyOnlyNew));

        $content = template('appleseed/email/scan-report', [
            'scan' => $scan,
            'summary' => $summary,
            'cpUrl' => $cpUrl,
            'brokenLinks' => $brokenLinks,
            'brokenLinkCount' => $summary['broken'],
            'onlyNew' => $this->settings->notifyOnlyNew,
        ], TemplateMode::Cp);

        $layoutVars = ['content' => $content, 'scan' => $scan, 'summary' => $summary, 'cpUrl' => $cpUrl];
        $layout = $this->settings->getEmailLayoutTemplateParsed();

        return $layout !== ''
            ? template($layout, $layoutVars, TemplateMode::Site)
            : template('appleseed/email/_default-layout', $layoutVars, TemplateMode::Cp);
    }

    public function reportSubject(Scan $scan): string
    {
        return $this->settings->notifyOnlyNew
            ? t('Appleseed: {count, plural, =1{# link} other{# links}} newly broken', ['count' => $scan->newBrokenCount], category: 'appleseed')
            : t('Appleseed: {count, plural, =1{# broken link} other{# broken links}} found', ['count' => $scan->brokenCount], category: 'appleseed');
    }

    /**
     * Source rows with their entry's title, section, status and edit URL.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function enrichSources(array $sources): array
    {
        $entries = $this->loadEntries(array_values(array_filter($sources, fn (array $s) => ! empty($s['entryId']))));

        foreach ($sources as &$source) {
            if (empty($source['entryId'])) {
                continue;
            }

            $entry = $entries["{$source['siteId']}:{$source['entryId']}"] ?? null;
            $source['entryTitle'] = $entry?->title;
            $source['entryCpUrl'] = $entry?->getCpEditUrl();
            $source['entrySection'] = $entry?->getSection()?->name;
            $source['entryStatus'] = $entry !== null ? $entry->getStatus() : 'deleted';
        }

        unset($source);

        return $sources;
    }

    /**
     * Entries by ID and site, keyed `"{siteId}:{entryId}"`.
     *
     * @param  list<array{entryId: mixed, siteId: mixed}>|array<array<string, mixed>>  $pairs
     * @return array<string, Entry>
     */
    private function loadEntries(array $pairs): array
    {
        $bySite = [];

        foreach ($pairs as $pair) {
            if (! empty($pair['entryId'])) {
                $bySite[(int) ($pair['siteId'] ?? 0)][(int) $pair['entryId']] = true;
            }
        }

        $entries = [];

        foreach ($bySite as $siteId => $ids) {
            $query = Entry::find()
                ->id(array_keys($ids))
                ->siteId($siteId ?: '*')
                ->status(null)
                ->drafts(null)
                ->provisionalDrafts(null)
                ->limit(null);

            foreach ($query->all() as $entry) {
                if ($entry instanceof Entry) {
                    $entries["{$siteId}:{$entry->id}"] ??= $entry;
                }
            }
        }

        return $entries;
    }
}
