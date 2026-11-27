<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Gql;

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Gql\GqlHelper;
use Throwable;

/**
 * Whether the active GraphQL schema may read Appleseed's links and scans.
 *
 * Link results show which pages of the site are broken and where content points (including
 * disabled entries' links), so they're only for a token's schema that has `appleseedLinks:read`,
 * and never for the public schema, whatever its scopes.
 */
final class GqlAccess
{
    /** The schema component; the permission is `appleseedLinks:read`. */
    public const string SCOPE = 'appleseedLinks';

    public static function canRead(?GqlSchema $schema = null): bool
    {
        try {
            $schema ??= app(Gql::class)->getActiveSchema();
        } catch (Throwable) {
            return false;
        }

        return ! $schema->isPublic && GqlHelper::canSchema(self::SCOPE, 'read', $schema);
    }
}
