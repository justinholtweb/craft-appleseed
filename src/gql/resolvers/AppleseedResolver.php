<?php

namespace justinholtweb\appleseed\gql\resolvers;

use craft\gql\resolvers\elements\Entry as EntryResolver;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\appleseed\gql\GqlAccess;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\helpers\Presenter;
use justinholtweb\appleseed\Plugin;

/**
 * Resolvers for Appleseed's GraphQL queries. Each re-checks the active schema, so a query that
 * slipped into a schema without the scope, or the public schema, still returns nothing.
 *
 * Links resolve to {@see Presenter::link()} arrays. Their sources come along only when the query
 * asks for them, loaded for the whole page at once.
 */
class AppleseedResolver
{
    public const MAX_LIMIT = 100;

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function links(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        if (!GqlAccess::canRead()) {
            return [];
        }

        $reporting = Plugin::getInstance()->reporting;
        $filters = self::filters($arguments);

        $sorts = ['url', 'status', 'statusCode', 'lastCheckedAt', 'host', 'firstBrokenAt', 'id'];
        $sort = in_array($arguments['orderBy'] ?? 'id', $sorts, true) ? $arguments['orderBy'] ?? 'id' : 'id';
        $direction = strtolower((string)($arguments['direction'] ?? 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $rows = $reporting->linkQuery($filters)
            ->select(['l.*'])
            ->orderBy(["l.$sort" => $direction, 'l.id' => SORT_ASC])
            ->offset(max(0, (int)($arguments['offset'] ?? 0)))
            ->limit(max(1, min(self::MAX_LIMIT, (int)($arguments['limit'] ?? 50))))
            ->all();

        return self::present($rows, $resolveInfo);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function linkCount(mixed $source, array $arguments): int
    {
        if (!GqlAccess::canRead()) {
            return 0;
        }

        return (int)Plugin::getInstance()->reporting->linkQuery(self::filters($arguments))->count();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function link(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): ?array
    {
        if (!GqlAccess::canRead()) {
            return null;
        }

        $reporting = Plugin::getInstance()->reporting;
        $id = isset($arguments['id']) ? (int)$arguments['id'] : null;

        if ($id === null && !empty($arguments['url'])) {
            $id = $reporting->getLinkIdByUrl((string)$arguments['url']);
        }

        if ($id === null) {
            return null;
        }

        $row = $reporting->linkQuery(['includeIgnored' => true])->select(['l.*'])->andWhere(['l.id' => $id])->one();

        return $row ? self::present([$row], $resolveInfo)[0] : null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function summary(mixed $source, array $arguments): ?array
    {
        if (!GqlAccess::canRead()) {
            return null;
        }

        $reporting = Plugin::getInstance()->reporting;
        $lastScan = $reporting->getLastCompletedScan();

        return $reporting->getDashboardSummary(isset($arguments['siteId']) ? (int)$arguments['siteId'] : null)
            + ['lastScan' => $lastScan ? Presenter::scan($lastScan) : null];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function scans(mixed $source, array $arguments): array
    {
        if (!GqlAccess::canRead()) {
            return [];
        }

        $scans = Plugin::getInstance()->reporting->getScans(
            max(1, min(self::MAX_LIMIT, (int)($arguments['limit'] ?? 20))),
            max(0, (int)($arguments['offset'] ?? 0)),
        );

        return array_values(array_map([Presenter::class, 'scan'], $scans));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function scan(mixed $source, array $arguments): ?array
    {
        if (!GqlAccess::canRead()) {
            return null;
        }

        $reporting = Plugin::getInstance()->reporting;
        $scan = isset($arguments['id'])
            ? $reporting->getScanById((int)$arguments['id'])
            : $reporting->getLastCompletedScan();

        return $scan ? Presenter::scan($scan) : null;
    }

    /**
     * `AppleseedLink.sources`.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function sources(array $source, array $arguments): array
    {
        if (!GqlAccess::canRead()) {
            return [];
        }

        $sources = $source['sources'] ?? [];
        $siteId = isset($arguments['siteId']) ? (int)$arguments['siteId'] : null;

        if ($siteId !== null) {
            $sources = array_filter($sources, fn(array $s) => $s['sourceType'] === 'spider' || $s['siteId'] === $siteId);
        }

        return array_values($sources);
    }

    /**
     * `AppleseedLinkSource.entry`, through Craft's own entry resolver, so the schema's section
     * permissions apply: an entry the token can't query comes back null.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $arguments
     */
    public static function entry(array $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (!GqlAccess::canRead() || empty($source['entryId'])) {
            return null;
        }

        return EntryResolver::resolveOne(null, array_filter([
            'id' => (int)$source['entryId'],
            'siteId' => $source['siteId'] ?? null,
        ]), $context, $resolveInfo);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function filters(array $arguments): array
    {
        $status = isset($arguments['status']) ? (string)$arguments['status'] : null;

        return [
            'status' => $status !== null && in_array($status, LinkStatus::ALL, true) ? $status : null,
            'search' => $arguments['search'] ?? null,
            'host' => $arguments['host'] ?? null,
            'siteId' => isset($arguments['siteId']) ? (int)$arguments['siteId'] : null,
            'entryId' => isset($arguments['entryId']) ? (int)$arguments['entryId'] : null,
            'includeIgnored' => (bool)($arguments['includeIgnored'] ?? false),
        ];
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function present(array $rows, ResolveInfo $resolveInfo): array
    {
        $fields = $resolveInfo->getFieldSelection();
        $withSources = isset($fields['sources']) || isset($fields['sourceCount']);

        if ($withSources) {
            $rows = Plugin::getInstance()->reporting->attachSources($rows);
        } else {
            $counts = self::sourceCounts(array_map(fn($row) => (int)$row['id'], $rows));
            foreach ($rows as &$row) {
                $row['sourceCount'] = $counts[(int)$row['id']] ?? 0;
            }
            unset($row);
        }

        return array_values(array_map(function(array $row) use ($withSources) {
            $data = Presenter::link($row, false);

            // Raw source rows for `sources`; `entryTitle` etc. aren't on the type, so they can't leak
            $data['sources'] = $withSources ? array_map([Presenter::class, 'source'], $row['sources']) : [];

            return $data;
        }, $rows));
    }

    /**
     * @param int[] $linkIds
     * @return array<int, int>
     */
    private static function sourceCounts(array $linkIds): array
    {
        if ($linkIds === []) {
            return [];
        }

        $rows = (new \yii\db\Query())
            ->select(['linkId', 'count' => 'COUNT(*)'])
            ->from('{{%appleseed_link_sources}}')
            ->where(['linkId' => $linkIds])
            ->groupBy(['linkId'])
            ->all();

        return array_map('intval', array_column($rows, 'count', 'linkId'));
    }
}
