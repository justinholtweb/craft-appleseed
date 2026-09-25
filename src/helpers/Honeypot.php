<?php

namespace justinholtweb\appleseed\helpers;

use Craft;

class Honeypot
{
    /**
     * Whether a URL points at a honeypot trap on one of this install's own sites.
     *
     * Black Hole hides a link to its trap path on every front-end page and bans whatever
     * address requests it -- on any method, HEAD included. The scan runs from the server's own
     * address, so a single request to the trap locks the scan (and on a dev machine, the whole
     * machine) out of the site. Trap URLs must never be fetched, by the spider or the checker.
     */
    public static function isTrapUrl(string $url): bool
    {
        $trapPath = self::_blackHoleTrapPath();
        if ($trapPath === null) {
            return false;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $urlPath = $parts['path'] ?? '/';

        // Sites with `omitScriptNameInUrls` off route via the path param, e.g. `/index.php?p=blackhole`
        $pathParam = Craft::$app->getConfig()->getGeneral()->pathParam;
        if ($pathParam && isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (isset($query[$pathParam]) && is_string($query[$pathParam]) && self::_matchesTrap($query[$pathParam], $trapPath)) {
                return self::_isSiteHost($host);
            }
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            if (!$baseUrl || strtolower((string) parse_url($baseUrl, PHP_URL_HOST)) !== $host) {
                continue;
            }

            $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
            if ($basePath !== '' && stripos($urlPath, $basePath . '/') !== 0) {
                continue;
            }

            $relativePath = substr($urlPath, strlen($basePath));
            $relativePath = preg_replace('#^/index\.php(?=/|$)#i', '', $relativePath) ?? $relativePath;

            if (self::_matchesTrap($relativePath, $trapPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Black Hole's trap path (no surrounding slashes), or null when it isn't installed and trapping.
     */
    private static function _blackHoleTrapPath(): ?string
    {
        // getPlugin() only returns plugins that are installed and enabled
        $plugin = Craft::$app->getPlugins()->getPlugin('blackhole');
        if ($plugin === null) {
            return null;
        }

        $settings = $plugin->getSettings();
        if ($settings === null || !($settings->enabled ?? false)) {
            return null;
        }

        $path = method_exists($settings, 'normalizedTrapPath')
            ? $settings->normalizedTrapPath()
            : trim(trim((string) ($settings->trapPath ?? '')), '/');

        return $path !== '' ? $path : null;
    }

    /**
     * Black Hole routes both the trap path and everything beneath it.
     */
    private static function _matchesTrap(string $path, string $trapPath): bool
    {
        $path = trim(rawurldecode($path), '/');

        return strcasecmp($path, $trapPath) === 0
            || stripos($path, $trapPath . '/') === 0;
    }

    private static function _isSiteHost(string $host): bool
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (strtolower((string) parse_url((string) $site->getBaseUrl(), PHP_URL_HOST)) === $host) {
                return true;
            }
        }

        return false;
    }
}
