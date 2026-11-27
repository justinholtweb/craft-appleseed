<?php

namespace justinholtweb\appleseed\helpers;

/**
 * Small URL utilities shared by the extractor, the spider and the scanner.
 */
final class LinkUrl
{
    /**
     * The URL without a `user:password@` part. Credentials someone pasted into a link are never
     * stored, shown on the dashboard, exported or sent in a notification.
     */
    public static function stripCredentials(string $url): string
    {
        return (string)preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#@]*@#i', '$1', $url);
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
}
