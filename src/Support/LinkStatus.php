<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use function CraftCms\Cms\t;

/**
 * Link status values, and which of them count as broken.
 *
 * One list for the scanner, the dashboard, the email, GraphQL and MCP, so they can't drift.
 */
final class LinkStatus
{
    public const string WORKING = 'working';

    public const string BROKEN = 'broken';

    public const string REDIRECT = 'redirect';

    public const string SERVER_ERROR = 'server_error';

    public const string TIMEOUT = 'timeout';

    public const string DNS_ERROR = 'dns_error';

    /** Bot protection answered instead of the site. Not counted as broken. */
    public const string BLOCKED = 'blocked';

    public const string IGNORED = 'ignored';

    public const string UNKNOWN = 'unknown';

    /** Statuses counted as broken: in the badge, the summary, scan tallies and notifications. */
    public const array BROKEN_STATUSES = [self::BROKEN, self::DNS_ERROR, self::TIMEOUT, self::SERVER_ERROR];

    /** Every status a link can have. */
    public const array ALL = [
        self::WORKING,
        self::BROKEN,
        self::REDIRECT,
        self::SERVER_ERROR,
        self::TIMEOUT,
        self::DNS_ERROR,
        self::BLOCKED,
        self::IGNORED,
        self::UNKNOWN,
    ];

    public static function isBroken(?string $status): bool
    {
        return in_array($status, self::BROKEN_STATUSES, true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::WORKING => t('Working', category: 'appleseed'),
            self::BROKEN => t('Broken', category: 'appleseed'),
            self::REDIRECT => t('Redirect', category: 'appleseed'),
            self::SERVER_ERROR => t('Server Error', category: 'appleseed'),
            self::TIMEOUT => t('Timeout', category: 'appleseed'),
            self::DNS_ERROR => t('DNS Error', category: 'appleseed'),
            self::BLOCKED => t('Blocked', category: 'appleseed'),
            self::IGNORED => t('Ignored', category: 'appleseed'),
            default => t('Not checked', category: 'appleseed'),
        };
    }

    /**
     * The options the dashboard's status filter offers, and the allowed values of the
     * `defaultStatusFilter` setting. An empty value means no filtering.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function filterOptions(): array
    {
        return [
            ['label' => t('All Statuses', category: 'appleseed'), 'value' => ''],
            ['label' => self::label(self::BROKEN), 'value' => self::BROKEN],
            ['label' => self::label(self::REDIRECT), 'value' => self::REDIRECT],
            ['label' => self::label(self::WORKING), 'value' => self::WORKING],
            ['label' => self::label(self::SERVER_ERROR), 'value' => self::SERVER_ERROR],
            ['label' => self::label(self::TIMEOUT), 'value' => self::TIMEOUT],
            ['label' => self::label(self::DNS_ERROR), 'value' => self::DNS_ERROR],
            ['label' => self::label(self::BLOCKED), 'value' => self::BLOCKED],
            ['label' => self::label(self::UNKNOWN), 'value' => self::UNKNOWN],
            ['label' => self::label(self::IGNORED), 'value' => self::IGNORED],
        ];
    }

    /**
     * A known status filter value, or null for "no filter".
     */
    public static function normalizeFilter(mixed $filter): ?string
    {
        return is_string($filter) && in_array($filter, self::ALL, true) ? $filter : null;
    }
}
