<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use DOMDocument;
use DOMXPath;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;

class Spider extends Component
{
    /**
     * Crawl all sites via BFS, returning discovered links.
     *
     * @return array<array{url: string, sourceUrl: string, linkText: string|null}>
     */
    public function crawl(?callable $progressCallback = null): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $client = Craft::createGuzzleClient([
            RequestOptions::TIMEOUT => $settings->timeout,
            RequestOptions::CONNECT_TIMEOUT => $settings->timeout,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => true,
            RequestOptions::VERIFY => true,
            RequestOptions::HEADERS => [
                'User-Agent' => $settings->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);

        $discoveredLinks = [];
        $visited = [];
        $queue = [];
        $internalHosts = [];
        $pagesVisited = 0;
        $maxPages = $settings->maxPagesToSpider;
        $rateLimit = $settings->rateLimitPerSecond;

        // Seed with all site base URLs
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = rtrim($site->getBaseUrl(), '/');
            if (!empty($baseUrl)) {
                $queue[] = $baseUrl;
                $host = parse_url($baseUrl, PHP_URL_HOST);
                if ($host) {
                    $internalHosts[$host] = true;
                }
            }
        }

        while (!empty($queue) && $pagesVisited < $maxPages) {
            $pageUrl = array_shift($queue);
            $normalizedPageUrl = $this->_normalizeUrl($pageUrl);

            if (isset($visited[$normalizedPageUrl])) {
                continue;
            }

            $visited[$normalizedPageUrl] = true;

            // Rate limit
            if ($rateLimit > 0) {
                usleep((int) (1_000_000 / $rateLimit));
            }

            try {
                $response = $client->get($pageUrl);
            } catch (GuzzleException) {
                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                continue;
            }

            $contentType = $response->getHeaderLine('Content-Type');
            if (!str_contains($contentType, 'text/html')) {
                continue;
            }

            $html = (string) $response->getBody();
            $links = $this->_extractLinksFromHtml($html, $pageUrl);

            foreach ($links as $link) {
                $discoveredLinks[] = [
                    'url' => $link['url'],
                    'sourceUrl' => $pageUrl,
                    'linkText' => $link['linkText'],
                ];

                // Queue internal links for further crawling
                $linkHost = parse_url($link['url'], PHP_URL_HOST);
                if ($linkHost && isset($internalHosts[$linkHost]) && !isset($visited[$this->_normalizeUrl($link['url'])])) {
                    // Only queue HTML pages (skip assets)
                    if (!preg_match('/\.(jpg|jpeg|png|gif|svg|webp|ico|css|js|pdf|zip|tar|gz|mp4|mp3|woff2?|ttf|eot)$/i', parse_url($link['url'], PHP_URL_PATH) ?? '')) {
                        $queue[] = $link['url'];
                    }
                }
            }

            $pagesVisited++;

            if ($progressCallback) {
                $progressCallback($pagesVisited, $maxPages);
            }
        }

        return $discoveredLinks;
    }

    /**
     * @return array<array{url: string, linkText: string|null}>
     */
    private function _extractLinksFromHtml(string $html, string $baseUrl): array
    {
        $links = [];

        if (empty($html)) {
            return $links;
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        $xpath = new DOMXPath($doc);

        // <a href>
        $anchors = $xpath->query('//a[@href]');
        if ($anchors) {
            foreach ($anchors as $anchor) {
                $href = trim($anchor->getAttribute('href'));
                $text = trim($anchor->textContent);
                $resolved = $this->_resolveUrl($href, $baseUrl);
                if ($resolved) {
                    $links[] = ['url' => $resolved, 'linkText' => $text ?: null];
                }
            }
        }

        // <img src>
        $images = $xpath->query('//img[@src]');
        if ($images) {
            foreach ($images as $img) {
                $src = trim($img->getAttribute('src'));
                $alt = trim($img->getAttribute('alt'));
                $resolved = $this->_resolveUrl($src, $baseUrl);
                if ($resolved) {
                    $links[] = ['url' => $resolved, 'linkText' => $alt ?: null];
                }
            }
        }

        libxml_clear_errors();

        return $links;
    }

    private function _resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if (empty($url)) {
            return null;
        }

        // Skip non-HTTP schemes
        if (preg_match('/^(mailto:|tel:|javascript:|data:|#)/', $url)) {
            return null;
        }

        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            return $this->_stripFragment($url);
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            return $this->_stripFragment('https:' . $url);
        }

        // Resolve relative URL
        $parsedBase = parse_url($baseUrl);
        if (!$parsedBase || !isset($parsedBase['host'])) {
            return null;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'];
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        if (str_starts_with($url, '/')) {
            return $this->_stripFragment("{$scheme}://{$host}{$port}{$url}");
        }

        $basePath = $parsedBase['path'] ?? '/';
        $baseDir = rtrim(dirname($basePath), '/');
        return $this->_stripFragment("{$scheme}://{$host}{$port}{$baseDir}/{$url}");
    }

    private function _stripFragment(string $url): string
    {
        $pos = strpos($url, '#');
        return $pos !== false ? substr($url, 0, $pos) : $url;
    }

    private function _normalizeUrl(string $url): string
    {
        $url = $this->_stripFragment($url);
        $url = rtrim($url, '/');
        return strtolower($url);
    }
}
