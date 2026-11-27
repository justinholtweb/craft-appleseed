<?php

namespace justinholtweb\appleseed\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\appleseed\gql\resolvers\AppleseedResolver;

/**
 * `AppleseedLink` — A URL found in the site's content or pages, and how it fared when checked.
 */
class AppleseedLinkType
{
    public static function getName(): string
    {
        return 'AppleseedLink';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A URL found in the site’s content or pages, and how it fared when checked.',
            'fields' => fn() => [
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
                    'type' => Type::listOf(AppleseedLinkSourceType::getType()),
                    'args' => [
                        'siteId' => ['type' => Type::int(), 'description' => 'Only places on this site.'],
                    ],
                    'resolve' => [AppleseedResolver::class, 'sources'],
                    'description' => 'Where the link was found: entry fields, or crawled pages.',
                ],
            ],
        ]));
    }
}
