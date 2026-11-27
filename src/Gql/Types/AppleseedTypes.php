<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Gql\Types;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\Interfaces\Elements\Entry as EntryInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Appleseed\Gql\Queries\AppleseedQueries;

/**
 * Appleseed's GraphQL object types: `AppleseedLink`, `AppleseedLinkSource`, `AppleseedScan` and
 * `AppleseedSummary`. Names and fields are the same as Appleseed 5.3's.
 */
final class AppleseedTypes
{
    public static function link(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('AppleseedLink', fn () => new ObjectType([
            'name' => 'AppleseedLink',
            'description' => 'A URL found in the site’s content or pages, and how it fared when checked.',
            'fields' => fn () => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'url' => ['type' => Type::nonNull(Type::string())],
                'host' => ['type' => Type::string()],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'working, broken, redirect, server_error, timeout, dns_error, blocked, ignored or unknown (not checked yet).'],
                'isBroken' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether the status counts as broken: broken, server_error, timeout or dns_error.'],
                'statusCode' => ['type' => Type::int(), 'description' => 'The HTTP status code, when there was a response.'],
                'redirectUrl' => ['type' => Type::string(), 'description' => 'Where a redirect ends up.'],
                'redirectChain' => ['type' => Type::listOf(Type::string()), 'description' => 'Each hop of a redirect, in order.'],
                'errorMessage' => ['type' => Type::string()],
                'isIgnored' => ['type' => Type::nonNull(Type::boolean())],
                'dateIgnored' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
                'lastCheckedAt' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
                'firstBrokenAt' => ['type' => Type::string(), 'description' => 'When the link started failing. ISO-8601.'],
                'sourceCount' => ['type' => Type::nonNull(Type::int()), 'description' => 'How many places the link was found.'],
                'sources' => [
                    'type' => Type::listOf(self::linkSource()),
                    'args' => [
                        'siteId' => ['type' => Type::int(), 'description' => 'Only places on this site.'],
                    ],
                    'resolve' => AppleseedQueries::sources(...),
                    'description' => 'Where the link was found: entry fields, or crawled pages.',
                ],
            ],
        ]));
    }

    public static function linkSource(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('AppleseedLinkSource', fn () => new ObjectType([
            'name' => 'AppleseedLinkSource',
            'description' => 'One place a link was found.',
            'fields' => fn () => [
                'sourceType' => ['type' => Type::nonNull(Type::string()), 'description' => '`database` (an entry’s field) or `spider` (a crawled page).'],
                'entryId' => ['type' => Type::int()],
                'siteId' => ['type' => Type::int()],
                'fieldHandle' => ['type' => Type::string(), 'description' => 'The field the link is in. Inside a Matrix field, the path, e.g. `blocks.body`.'],
                'linkText' => ['type' => Type::string()],
                'sourceUrl' => ['type' => Type::string(), 'description' => 'The crawled page the link was on (spider sources).'],
                'entry' => [
                    'type' => EntryInterface::getType(),
                    'resolve' => AppleseedQueries::entry(...),
                    'description' => 'The entry the link was found in — only when the schema can query that entry’s section and site.',
                ],
            ],
        ]));
    }

    public static function scan(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('AppleseedScan', fn () => new ObjectType([
            'name' => 'AppleseedScan',
            'description' => 'One Appleseed scan.',
            'fields' => fn () => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'full, database, section, entry, incremental or recheck.'],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'running, completed, failed or cancelled.'],
                'totalLinksFound' => ['type' => Type::nonNull(Type::int())],
                'totalLinksChecked' => ['type' => Type::nonNull(Type::int())],
                'brokenCount' => ['type' => Type::nonNull(Type::int())],
                'newBrokenCount' => ['type' => Type::nonNull(Type::int()), 'description' => 'Links that broke during this scan.'],
                'redirectCount' => ['type' => Type::nonNull(Type::int())],
                'workingCount' => ['type' => Type::nonNull(Type::int())],
                'entryId' => ['type' => Type::int(), 'description' => 'The entry, for an entry scan.'],
                'startedAt' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
                'completedAt' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
            ],
        ]));
    }

    public static function summary(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('AppleseedSummary', fn () => new ObjectType([
            'name' => 'AppleseedSummary',
            'description' => 'How the site’s links stand. Ignored links are only counted in `ignored`.',
            'fields' => fn () => [
                'total' => ['type' => Type::nonNull(Type::int())],
                'broken' => ['type' => Type::nonNull(Type::int())],
                'redirects' => ['type' => Type::nonNull(Type::int())],
                'working' => ['type' => Type::nonNull(Type::int())],
                'blocked' => ['type' => Type::nonNull(Type::int()), 'description' => 'Bot protection answered instead of the site; not counted as broken.'],
                'unchecked' => ['type' => Type::nonNull(Type::int())],
                'ignored' => ['type' => Type::nonNull(Type::int())],
                'lastScan' => ['type' => self::scan(), 'description' => 'The most recent completed scan.'],
            ],
        ]));
    }
}
