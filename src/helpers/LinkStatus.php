<?php

namespace justinholtweb\appleseed\helpers;

use Craft;

/**
 * Link status values, and which of them count as broken.
 *
 * One list for the scanner, the dashboard, the email, GraphQL and MCP, so they can't drift.
 */
final class LinkStatus
{
    public const WORKING = 'working';
    public const BROKEN = 'broken';
    public const REDIRECT = 'redirect';
    public const SERVER_ERROR = 'server_error';
    public const TIMEOUT = 'timeout';
    public const DNS_ERROR = 'dns_error';
    public const BLOCKED = 'blocked';
    public const IGNORED = 'ignored';
    public const UNKNOWN = 'unknown';

    /** Statuses counted as broken: in the badge, the summary, scan tallies and notifications. */
    public const BROKEN_STATUSES = [self::BROKEN, self::DNS_ERROR, self::TIMEOUT, self::SERVER_ERROR];

    /** Every status a link can have. */
    public const ALL = [
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
            self::WORKING => Craft::t('appleseed', 'Working'),
            self::BROKEN => Craft::t('appleseed', 'Broken'),
            self::REDIRECT => Craft::t('appleseed', 'Redirect'),
            self::SERVER_ERROR => Craft::t('appleseed', 'Server Error'),
            self::TIMEOUT => Craft::t('appleseed', 'Timeout'),
            self::DNS_ERROR => Craft::t('appleseed', 'DNS Error'),
            self::BLOCKED => Craft::t('appleseed', 'Blocked'),
            self::IGNORED => Craft::t('appleseed', 'Ignored'),
            default => Craft::t('appleseed', 'Not checked'),
        };
    }

    /**
     * The condition a status filter adds to a query on `appleseed_links` (aliased `$alias`), or
     * null for no filter. `ignored` shows ignored links; every other filter hides them.
     *
     * @return array<mixed>
     */
    public static function condition(?string $filter, string $alias = 'l'): array
    {
        $prefix = $alias !== '' ? "$alias." : '';

        if ($filter === self::IGNORED) {
            return ["{$prefix}isIgnored" => true];
        }

        $condition = ['and', ["{$prefix}isIgnored" => false]];

        if ($filter === self::BROKEN) {
            $condition[] = ["{$prefix}status" => self::BROKEN_STATUSES];
        } elseif ($filter !== null && $filter !== '' && in_array($filter, self::ALL, true)) {
            $condition[] = ["{$prefix}status" => $filter];
        }

        return $condition;
    }
}
