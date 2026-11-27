<?php

namespace justinholtweb\appleseed\helpers;

use Craft;

/**
 * The kinds of scan Appleseed runs.
 */
final class ScanType
{
    /** Every live entry's fields, plus the spider when it's enabled. */
    public const FULL = 'full';

    /** Every live entry's fields, without the spider. */
    public const DATABASE = 'database';

    /** Entries in chosen sections. */
    public const SECTION = 'section';

    /** One entry, usually after it was saved. */
    public const ENTRY = 'entry';

    /** Entries changed since the last completed scan. */
    public const INCREMENTAL = 'incremental';

    /** Re-check the links already known to be broken, without reading any content. */
    public const RECHECK = 'recheck';

    /** The types a person can start from the dashboard, the console, MCP or the schedule. */
    public const STARTABLE = [self::FULL, self::DATABASE, self::INCREMENTAL, self::RECHECK];

    public static function label(string $type): string
    {
        return match ($type) {
            self::FULL => Craft::t('appleseed', 'Full scan'),
            self::DATABASE => Craft::t('appleseed', 'Content scan'),
            self::SECTION => Craft::t('appleseed', 'Section scan'),
            self::ENTRY => Craft::t('appleseed', 'Entry scan'),
            self::INCREMENTAL => Craft::t('appleseed', 'Changed entries'),
            self::RECHECK => Craft::t('appleseed', 'Re-check broken links'),
            default => $type,
        };
    }
}
