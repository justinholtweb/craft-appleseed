<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Gql\Queries;

use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use CraftCms\Cms\Gql\Resolvers\Elements\Entry as EntryResolver;
use CraftCms\Cms\Site\Data\Site;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Appleseed\Database\Tables;
use JustinHoltWeb\Appleseed\Gql\GqlAccess;
use JustinHoltWeb\Appleseed\Gql\Types\AppleseedTypes;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\Presenter;
use Override;

/**
 * `appleseedLinks`, `appleseedLinkCount`, `appleseedLink`, `appleseedSummary`, `appleseedScans`,
 * `appleseedScan`: read-only, and only for a schema with `appleseedLinks:read` that isn't the
 * public schema ({@see GqlAccess}). Each resolver checks again, so a query that slipped into a
 * schema without the scope still returns nothing.
 *
 * Links resolve to {@see Presenter::link()} arrays. Their sources come along only when the query
 * asks for them, loaded for the whole page at once.
 */
final class AppleseedQueries extends Query
{
    public const int MAX_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlAccess::canRead()) {
            return [];
        }

        $filters = [
            'status' => ['type' => Type::string(), 'description' => 'A status (working, redirect, blocked, unknown…), `broken` for every broken status, or `ignored` for ignored links.'],
            'search' => ['type' => Type::string(), 'description' => 'Part of the URL.'],
            'host' => ['type' => Type::string(), 'description' => 'The link’s host, e.g. `example.com`.'],
            'siteId' => ['type' => Type::int(), 'description' => 'Only links found on this site.'],
            'entryId' => ['type' => Type::int(), 'description' => 'Only links found in this entry.'],
            'includeIgnored' => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'With no status, include ignored links.'],
        ];

        return [
            'appleseedLinks' => [
                'type' => Type::listOf(AppleseedTypes::link()),
                'args' => $filters + [
                    'limit' => ['type' => Type::int(), 'defaultValue' => 50, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                    'orderBy' => ['type' => Type::string(), 'defaultValue' => 'id', 'description' => 'id, url, status, statusCode, host, lastCheckedAt or firstBrokenAt.'],
                    'direction' => ['type' => Type::string(), 'defaultValue' => 'asc', 'description' => 'asc or desc.'],
                ],
                'resolve' => self::links(...),
                'description' => 'Links Appleseed has found, with their check results. Ignored links are left out unless asked for.',
            ],
            'appleseedLinkCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => $filters,
                'resolve' => self::linkCount(...),
                'description' => 'How many links match the filters, for paging through appleseedLinks.',
            ],
            'appleseedLink' => [
                'type' => AppleseedTypes::link(),
                'args' => [
                    'id' => ['type' => Type::int()],
                    'url' => ['type' => Type::string(), 'description' => 'The exact URL, as Appleseed stored it.'],
                ],
                'resolve' => self::link(...),
                'description' => 'One link, by ID or URL.',
            ],
            'appleseedSummary' => [
                'type' => AppleseedTypes::summary(),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Only links found on this site.'],
                ],
                'resolve' => self::summary(...),
                'description' => 'Counts of working, broken, redirecting, blocked and ignored links, and the last scan.',
            ],
            'appleseedScans' => [
                'type' => Type::listOf(AppleseedTypes::scan()),
                'args' => [
                    'limit' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => self::scans(...),
                'description' => 'Appleseed scans, newest first.',
            ],
            'appleseedScan' => [
                'type' => AppleseedTypes::scan(),
                'args' => [
                    'id' => ['type' => Type::int(), 'description' => 'Omit for the latest completed scan.'],
                ],
                'resolve' => self::scan(...),
                'description' => 'One scan, or the latest completed one.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function links(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        if (! GqlAccess::canRead()) {
            return [];
        }

        $reporting = app(Reporting::class);
        $sort = in_array($arguments['orderBy'] ?? 'id', Reporting::SORTS, true) ? (string) ($arguments['orderBy'] ?? 'id') : 'id';

        $links = $reporting->sorted($reporting->linkQuery(self::filters($arguments)), $sort, (string) ($arguments['direction'] ?? 'asc'))
            ->offset(max(0, (int) ($arguments['offset'] ?? 0)))
            ->limit(max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 50))))
            ->get()
            ->all();

        return self::present($links, $resolveInfo);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function linkCount(mixed $source, array $arguments): int
    {
        return GqlAccess::canRead() ? app(Reporting::class)->linkQuery(self::filters($arguments))->count() : 0;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public static function link(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): ?array
    {
        if (! GqlAccess::canRead()) {
            return null;
        }

        $id = isset($arguments['id']) ? (int) $arguments['id'] : null;

        if ($id === null && ! empty($arguments['url'])) {
            $id = app(Reporting::class)->getLinkIdByUrl((string) $arguments['url']);
        }

        $link = $id !== null ? Link::query()->find($id) : null;

        return $link instanceof Link ? self::present([$link], $resolveInfo)[0] : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public static function summary(mixed $source, array $arguments): ?array
    {
        if (! GqlAccess::canRead()) {
            return null;
        }

        $reporting = app(Reporting::class);
        $lastScan = $reporting->getLastCompletedScan();

        return $reporting->getDashboardSummary(isset($arguments['siteId']) ? (int) $arguments['siteId'] : null)
            + ['lastScan' => $lastScan !== null ? Presenter::scan($lastScan) : null];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function scans(mixed $source, array $arguments): array
    {
        if (! GqlAccess::canRead()) {
            return [];
        }

        $scans = app(Reporting::class)->getScans(
            max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? 20))),
            max(0, (int) ($arguments['offset'] ?? 0)),
        );

        return array_map(fn (Scan $scan) => Presenter::scan($scan), $scans);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public static function scan(mixed $source, array $arguments): ?array
    {
        if (! GqlAccess::canRead()) {
            return null;
        }

        $reporting = app(Reporting::class);
        $scan = isset($arguments['id']) ? $reporting->getScanById((int) $arguments['id']) : $reporting->getLastCompletedScan();

        return $scan !== null ? Presenter::scan($scan) : null;
    }

    /**
     * `AppleseedLink.sources`.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function sources(array $source, array $arguments): array
    {
        if (! GqlAccess::canRead()) {
            return [];
        }

        /** @var list<array<string, mixed>> $sources */
        $sources = $source['sources'] ?? [];
        $siteId = isset($arguments['siteId']) ? (int) $arguments['siteId'] : null;

        if ($siteId !== null) {
            $sources = array_filter($sources, fn (array $s) => $s['sourceType'] === 'spider' || $s['siteId'] === $siteId);
        }

        return array_values($sources);
    }

    /**
     * `AppleseedLinkSource.entry`, through Craft's own entry resolver, so the schema's section
     * access applies, and only on a site the schema can read: anything else comes back null.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $arguments
     */
    public static function entry(array $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (! GqlAccess::canRead() || empty($source['entryId'])) {
            return null;
        }

        $siteId = isset($source['siteId']) ? (int) $source['siteId'] : null;

        // Craft's element resolvers apply section scopes but not the schema's site scope.
        if ($siteId !== null && ! in_array($siteId, array_map(fn (Site $site) => $site->id, GqlHelper::getAllowedSites()), true)) {
            return null;
        }

        return EntryResolver::resolveOne(null, array_filter([
            'id' => (int) $source['entryId'],
            'siteId' => $siteId,
        ]), is_array($context) ? $context : null, $resolveInfo);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function filters(array $arguments): array
    {
        return [
            'status' => LinkStatus::normalizeFilter($arguments['status'] ?? null),
            'search' => $arguments['search'] ?? null,
            'host' => $arguments['host'] ?? null,
            'siteId' => isset($arguments['siteId']) ? (int) $arguments['siteId'] : null,
            'entryId' => isset($arguments['entryId']) ? (int) $arguments['entryId'] : null,
            'includeIgnored' => (bool) ($arguments['includeIgnored'] ?? false),
        ];
    }

    /**
     * @param  list<Link>  $links
     * @return list<array<string, mixed>>
     */
    private static function present(array $links, ResolveInfo $resolveInfo): array
    {
        $fields = $resolveInfo->getFieldSelection();
        $withSources = isset($fields['sources']) || isset($fields['sourceCount']);

        if ($withSources) {
            $rows = app(Reporting::class)->withSources($links);
        } else {
            $counts = self::sourceCounts(array_map(fn (Link $link) => $link->id, $links));
            $rows = array_map(fn (Link $link) => Presenter::linkData($link) + ['sourceCount' => $counts[$link->id] ?? 0], $links);
        }

        return array_map(function (array $row) use ($withSources): array {
            $data = Presenter::link($row, false);
            // Raw source rows for `sources`; `entryTitle` etc. aren't on the type, so they can't leak
            $data['sources'] = $withSources ? array_map(Presenter::source(...), $row['sources']) : [];

            return $data;
        }, $rows);
    }

    /**
     * @param  list<int>  $linkIds
     * @return array<int, int>
     */
    private static function sourceCounts(array $linkIds): array
    {
        if ($linkIds === []) {
            return [];
        }

        return DB::table(Tables::LINK_SOURCES)
            ->whereIn('linkId', $linkIds)
            ->selectRaw('linkId, COUNT(*) as aggregate')
            ->groupBy('linkId')
            ->pluck('aggregate', 'linkId')
            ->mapWithKeys(fn (mixed $count, mixed $linkId) => [(int) $linkId => (int) $count])
            ->all();
    }
}
