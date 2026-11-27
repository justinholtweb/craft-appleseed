<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use function CraftCms\Cms\t;

/**
 * The kinds of scan Appleseed runs.
 */
final class ScanType
{
    /** Every live entry's fields, plus the spider when it's enabled. */
    public const string FULL = 'full';

    /** Every live entry's fields, without the spider. */
    public const string DATABASE = 'database';

    /** Entries in chosen sections. */
    public const string SECTION = 'section';

    /** One entry, usually after it was saved. */
    public const string ENTRY = 'entry';

    /** Entries changed since the last completed scan. */
    public const string INCREMENTAL = 'incremental';

    /** Re-check the links already known to be broken, without reading any content. */
    public const string RECHECK = 'recheck';

    /** The types a person can start from the dashboard, the console, MCP or the schedule. */
    public const array STARTABLE = [self::FULL, self::DATABASE, self::INCREMENTAL, self::RECHECK];

    /** Scans that read every entry's content: the schedule and incremental scans are measured from these. */
    public const array CONTENT = [self::FULL, self::DATABASE, self::INCREMENTAL];

    public static function label(string $type): string
    {
        return match ($type) {
            self::FULL => t('Full scan', category: 'appleseed'),
            self::DATABASE => t('Content scan', category: 'appleseed'),
            self::SECTION => t('Section scan', category: 'appleseed'),
            self::ENTRY => t('Entry scan', category: 'appleseed'),
            self::INCREMENTAL => t('Changed entries', category: 'appleseed'),
            self::RECHECK => t('Re-check broken links', category: 'appleseed'),
            default => $type,
        };
    }
}
