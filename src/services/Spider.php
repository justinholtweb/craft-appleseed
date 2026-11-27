<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Support\Honeypot;
use JustinHoltWeb\Appleseed\Support\HostThrottle;
use JustinHoltWeb\Appleseed\Support\LinkUrl;
use Throwable;

/**
 * A breadth-first crawl of the sites' rendered pages, recording every `<a href>` and `<img src>`.
 *
 * Only the sites' own hosts are crawled. `rel="nofollow"` links are recorded but not followed,
 * ignore patterns keep the spider out of a path, and Black Hole's trap is never requested.
 */
class Spider
{
    private const string ASSET_EXTENSIONS = '/\.(jpg|jpeg|png|gif|svg|webp|avif|ico|css|js|mjs|pdf|zip|tar|gz|mp4|mp3|webm|woff2?|ttf|eot)$/i';

    public function __construct(
        private readonly Settings $settings,
        private readonly HostThrottle $throttle,
    ) {}

    /**
     * @param  (callable(int, int): void)|null  $progress  Called with pages visited and the page limit.
     * @return list<array{url: string, sourceUrl: string, linkText: string|null}>
     */
    public function crawl(?callable $progress = null): array
    {
        $discovered = [];
        $visited = [];
        $queue = [];
        $internalHosts = [];
        $pagesVisited = 0;
        $maxPages = $this->settings->maxPagesToSpider;

        /** @var Site $site */
        foreach (Sites::getAllSites() as $site) {
            $baseUrl = rtrim((string) $site->getBaseUrl(), '/');

            if ($baseUrl !== '' && ($host = LinkUrl::host($baseUrl)) !== null) {
                $queue[] = $baseUrl;
                $internalHosts[$host] = true;
            }
        }

        while ($queue !== [] && $pagesVisited < $maxPages) {
            $pageUrl = (string) array_shift($queue);
            $key = self::visitKey($pageUrl);

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;

            // Ignored URLs are never fetched: a pattern is how a site keeps the spider out of a path
            if (Honeypot::isTrapUrl($pageUrl) || $this->settings->matchesIgnorePattern($pageUrl)) {
                continue;
            }

            $html = $this->fetch($pageUrl);
            $pagesVisited++;

            if ($html !== null) {
                foreach (LinkExtractor::linksInHtml($html) as $link) {
                    $url = self::resolve($link['url'], $pageUrl);

                    // Don't even record a honeypot trap: checking it would spring it just the same
                    if ($url === null || Honeypot::isTrapUrl($url)) {
                        continue;
                    }

                    $discovered[] = ['url' => $url, 'sourceUrl' => $pageUrl, 'linkText' => $link['linkText']];

                    // Nofollow links are still checked, but not followed through the site
                    if ($link['nofollow'] || $this->settings->matchesIgnorePattern($url)) {
                        continue;
                    }

                    $host = LinkUrl::host($url);

                    if ($host !== null && isset($internalHosts[$host]) && ! isset($visited[self::visitKey($url)])
                        && ! preg_match(self::ASSET_EXTENSIONS, (string) parse_url($url, PHP_URL_PATH))) {
                        $queue[] = $url;
                    }
                }
            }

            if ($progress !== null) {
                $progress($pagesVisited, $maxPages);
            }
        }

        return $discovered;
    }

    /**
     * The page's HTML, or null when it isn't a 2xx HTML page.
     */
    private function fetch(string $url): ?string
    {
        $host = LinkUrl::host($url);

        if ($host !== null) {
            $this->throttle->wait($host, $this->settings->rateLimitPerSecond);
        }

        try {
            $response = Http::timeout($this->settings->timeout)
                ->connectTimeout($this->settings->timeout)
                ->withUserAgent($this->settings->getUserAgentParsed())
                ->accept('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'text/html')) {
            return null;
        }

        return $response->body();
    }

    /**
     * An href or src from a page, made absolute against the page, without its fragment. Null for
     * anything that isn't an http(s) link.
     */
    public static function resolve(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '' || preg_match('/^(mailto:|tel:|sms:|javascript:|data:|#)/i', $url)) {
            return null;
        }

        // Unparsed element reference tags show up when a template outputs raw rich text
        if (preg_match(LinkUrl::REF_TAG_PATTERN, $url)) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            return self::stripFragment(LinkUrl::stripCredentials($url));
        }

        if (str_starts_with($url, '//')) {
            return self::stripFragment(LinkUrl::stripCredentials('https:'.$url));
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return null;
        }

        $base = parse_url($baseUrl);

        if (! is_array($base) || ! isset($base['host'])) {
            return null;
        }

        $origin = ($base['scheme'] ?? 'https').'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($url, '/')) {
            return self::stripFragment($origin.$url);
        }

        $baseDir = rtrim(dirname($base['path'] ?? '/'), '/');

        return self::stripFragment("{$origin}{$baseDir}/{$url}");
    }

    private static function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos !== false ? substr($url, 0, $pos) : $url;
    }

    private static function visitKey(string $url): string
    {
        return strtolower(rtrim(self::stripFragment($url), '/'));
    }
}
