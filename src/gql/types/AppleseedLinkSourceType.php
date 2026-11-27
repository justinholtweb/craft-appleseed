<?php

namespace justinholtweb\appleseed\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Entry as EntryInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\appleseed\gql\resolvers\AppleseedResolver;

/**
 * `AppleseedLinkSource` — One place a link was found.
 */
class AppleseedLinkSourceType
{
    public static function getName(): string
    {
        return 'AppleseedLinkSource';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'One place a link was found.',
            'fields' => fn() => [
                'sourceType' => ['type' => Type::nonNull(Type::string()), 'description' => '`database` (an entry’s field) or `spider` (a crawled page).'],
                'entryId' => ['type' => Type::int()],
                'siteId' => ['type' => Type::int()],
                'fieldHandle' => ['type' => Type::string(), 'description' => 'The field the link is in. Inside a Matrix field, the path, e.g. `blocks.body`.'],
                'linkText' => ['type' => Type::string()],
                'sourceUrl' => ['type' => Type::string(), 'description' => 'The crawled page the link was on (spider sources).'],
                'entry' => [
                    'type' => EntryInterface::getType(),
                    'resolve' => [AppleseedResolver::class, 'entry'],
                    'description' => 'The entry the link was found in — only when the schema can query that entry’s section.',
                ],
            ],
        ]));
    }
}
