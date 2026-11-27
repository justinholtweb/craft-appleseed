<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sites;
use Throwable;

/**
 * Craft Black Hole's trap, which Appleseed must never request.
 *
 * Black Hole hides a link to its trap path on every front-end page and bans whatever address
 * requests it, on any method, HEAD included. The scan runs from the server's own address, so one
 * request to the trap locks the scan (and on a dev machine, the whole machine) out of the site.
 * Both the spider and the link checker ask here before any request.
 */
final class Honeypot
{
    /**
     * Whether a URL points at a honeypot trap on one of this install's own sites.
     */
    public static function isTrapUrl(string $url): bool
    {
        $trapPath = self::blackHoleTrapPath();

        if ($trapPath === null) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $urlPath = $parts['path'] ?? '/';

        // `?p=blackhole`-style routing, as Craft 5 sites with `omitScriptNameInUrls` off used.
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            if (isset($query['p']) && is_string($query['p']) && self::matchesTrap($query['p'], $trapPath)) {
                return in_array($host, LinkUrl::siteHosts(), true);
            }
        }

        /** @var Site $site */
        foreach (Sites::getAllSites() as $site) {
            $baseUrl = (string) $site->getBaseUrl();

            if ($baseUrl === '' || LinkUrl::host($baseUrl) !== $host) {
                continue;
            }

            $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

            if ($basePath !== '' && stripos($urlPath, $basePath.'/') !== 0) {
                continue;
            }

            $relativePath = substr($urlPath, strlen($basePath));
            $relativePath = preg_replace('#^/index\.php(?=/|$)#i', '', $relativePath) ?? $relativePath;

            if (self::matchesTrap($relativePath, $trapPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Black Hole's trap path (no surrounding slashes), or null when it isn't installed and trapping.
     */
    private static function blackHoleTrapPath(): ?string
    {
        try {
            $plugin = Plugins::getPlugin('blackhole');
            $settings = $plugin?->getSettings();
        } catch (Throwable) {
            return null;
        }

        if ($settings === null || ! ($settings->enabled ?? false)) {
            return null;
        }

        $path = method_exists($settings, 'normalizedTrapPath')
            ? (string) $settings->normalizedTrapPath()
            : trim(trim((string) ($settings->trapPath ?? '')), '/');

        return $path !== '' ? $path : null;
    }

    /**
     * Black Hole routes both the trap path and everything beneath it.
     */
    private static function matchesTrap(string $path, string $trapPath): bool
    {
        $path = trim(rawurldecode($path), '/');

        return strcasecmp($path, $trapPath) === 0
            || stripos($path, $trapPath.'/') === 0;
    }
}
