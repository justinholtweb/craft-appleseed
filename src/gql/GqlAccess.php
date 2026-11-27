<?php

namespace justinholtweb\appleseed\gql;

use Craft;
use craft\errors\GqlException;
use craft\helpers\Gql as GqlHelper;
use craft\models\GqlSchema;

/**
 * Whether the active GraphQL schema may read Appleseed's links and scans.
 *
 * Link results show which pages of the site are broken and where content points (including
 * drafts' and disabled entries' links), so they're only for a token's schema that has
 * `appleseedLinks:read`, and never for the public schema, whatever its scopes.
 */
class GqlAccess
{
    /** The schema component; the permission is `appleseedLinks:read`. */
    public const SCOPE = 'appleseedLinks';

    public static function canRead(?GqlSchema $schema = null): bool
    {
        if ($schema === null) {
            try {
                $schema = Craft::$app->getGql()->getActiveSchema();
            } catch (GqlException) {
                return false;
            }
        }

        return !$schema->isPublic && GqlHelper::canSchema(self::SCOPE, 'read', $schema);
    }
}
