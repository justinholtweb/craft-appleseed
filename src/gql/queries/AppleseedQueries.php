<?php

namespace justinholtweb\appleseed\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use justinholtweb\appleseed\gql\GqlAccess;
use justinholtweb\appleseed\gql\resolvers\AppleseedResolver;
use justinholtweb\appleseed\gql\types\AppleseedLinkType;
use justinholtweb\appleseed\gql\types\AppleseedScanType;
use justinholtweb\appleseed\gql\types\AppleseedSummaryType;

/**
 * Appleseed's read-only GraphQL queries. In the schema only when it has `appleseedLinks:read`
 * and isn't the public schema.
 */
class AppleseedQueries extends Query
{
    /**
     * @return array<string, mixed>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlAccess::canRead()) {
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
                'type' => Type::listOf(AppleseedLinkType::getType()),
                'args' => $filters + [
                    'limit' => ['type' => Type::int(), 'defaultValue' => 50, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                    'orderBy' => ['type' => Type::string(), 'defaultValue' => 'id', 'description' => 'id, url, status, statusCode, host, lastCheckedAt or firstBrokenAt.'],
                    'direction' => ['type' => Type::string(), 'defaultValue' => 'asc', 'description' => 'asc or desc.'],
                ],
                'resolve' => [AppleseedResolver::class, 'links'],
                'description' => 'Links Appleseed has found, with their check results. Ignored links are left out unless asked for.',
            ],
            'appleseedLinkCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => $filters,
                'resolve' => [AppleseedResolver::class, 'linkCount'],
                'description' => 'How many links match the filters, for paging through appleseedLinks.',
            ],
            'appleseedLink' => [
                'type' => AppleseedLinkType::getType(),
                'args' => [
                    'id' => ['type' => Type::int()],
                    'url' => ['type' => Type::string(), 'description' => 'The exact URL, as Appleseed stored it.'],
                ],
                'resolve' => [AppleseedResolver::class, 'link'],
                'description' => 'One link, by ID or URL.',
            ],
            'appleseedSummary' => [
                'type' => AppleseedSummaryType::getType(),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Only links found on this site.'],
                ],
                'resolve' => [AppleseedResolver::class, 'summary'],
                'description' => 'Counts of working, broken, redirecting, blocked and ignored links, and the last scan.',
            ],
            'appleseedScans' => [
                'type' => Type::listOf(AppleseedScanType::getType()),
                'args' => [
                    'limit' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => [AppleseedResolver::class, 'scans'],
                'description' => 'Appleseed scans, newest first.',
            ],
            'appleseedScan' => [
                'type' => AppleseedScanType::getType(),
                'args' => [
                    'id' => ['type' => Type::int(), 'description' => 'Omit for the latest completed scan.'],
                ],
                'resolve' => [AppleseedResolver::class, 'scan'],
                'description' => 'One scan, or the latest completed one.',
            ],
        ];
    }
}
