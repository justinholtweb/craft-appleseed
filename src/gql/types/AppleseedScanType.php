<?php

namespace justinholtweb\appleseed\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `AppleseedScan` — One Appleseed scan.
 */
class AppleseedScanType
{
    public static function getName(): string
    {
        return 'AppleseedScan';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'One Appleseed scan.',
            'fields' => fn() => [
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
}
