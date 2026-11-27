<?php

namespace justinholtweb\appleseed\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `AppleseedSummary` — How the site's links stand.
 */
class AppleseedSummaryType
{
    public static function getName(): string
    {
        return 'AppleseedSummary';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'How the site’s links stand. Ignored links are only counted in `ignored`.',
            'fields' => fn() => [
                'total' => ['type' => Type::nonNull(Type::int())],
                'broken' => ['type' => Type::nonNull(Type::int())],
                'redirects' => ['type' => Type::nonNull(Type::int())],
                'working' => ['type' => Type::nonNull(Type::int())],
                'blocked' => ['type' => Type::nonNull(Type::int()), 'description' => 'Bot protection answered instead of the site; not counted as broken.'],
                'unchecked' => ['type' => Type::nonNull(Type::int())],
                'ignored' => ['type' => Type::nonNull(Type::int())],
                'lastScan' => ['type' => AppleseedScanType::getType(), 'description' => 'The most recent completed scan.'],
            ],
        ]));
    }
}
