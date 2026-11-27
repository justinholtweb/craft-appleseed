<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Database;

/**
 * Appleseed's table names, unprefixed: Laravel adds the connection's prefix itself.
 *
 * Here rather than on the install migration, which Craft `require`s outside the autoloader.
 */
final class Tables
{
    public const string SCANS = 'appleseed_scans';

    public const string LINKS = 'appleseed_links';

    public const string LINK_SOURCES = 'appleseed_link_sources';
}
