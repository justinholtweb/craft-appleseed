<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;

/**
 * Small URL utilities shared by the extractor, the spider and the scanner.
 */
final class LinkUrl
{
    /** Matches an element reference tag, e.g. `{entry:123@1:url||https://example.com/page}`. */
    public const string REF_TAG_PATTERN = '/\{\w+\:[^\}]+\}/';

    /**
     * The URL without a `user:password@` part. Credentials someone pasted into a link are never
     * stored, shown on the dashboard, exported or sent in a notification.
     */
    public static function stripCredentials(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#@]*@#i', '$1', $url);
    }

    /**
     * The URL's lowercased host, or null when it has none.
     */
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * The storage key for a URL. Links are deduplicated on it.
     */
    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * The hosts of this install's sites, lowercased.
     *
     * @return list<string>
     */
    public static function siteHosts(): array
    {
        $hosts = Sites::getAllSites()
            ->map(fn (Site $site) => self::host((string) $site->getBaseUrl()))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_values(array_map('strval', $hosts));
    }

    /**
     * Whether a URL points somewhere other than one of this install's sites.
     */
    public static function isExternal(string $url): bool
    {
        $host = self::host($url);

        return $host !== null && ! in_array($host, self::siteHosts(), true);
    }
}
