<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\fields\Url as UrlField;
use craft\fields\Link as LinkField;
use craft\fields\Matrix as MatrixField;
use craft\fields\Table as TableField;
use DOMDocument;
use DOMXPath;

class LinkExtractor extends Component
{
    /**
     * Discovered links: [['url' => string, 'entryId' => int, 'siteId' => int, 'fieldHandle' => string, 'linkText' => string|null]]
     */
    private array $_links = [];

    /**
     * Extract all links from all entries across all sites.
     *
     * @return array<array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}>
     */
    public function extractAllLinks(?callable $progressCallback = null): array
    {
        $this->_links = [];

        $sites = Craft::$app->getSites()->getAllSites();
        $totalProcessed = 0;

        foreach ($sites as $site) {
            $query = Entry::find()
                ->siteId($site->id)
                ->status('live')
                ->limit(null);

            $totalEntries = $query->count();
            $offset = 0;
            $batchSize = 100;

            while ($offset < $totalEntries) {
                $entries = (clone $query)
                    ->offset($offset)
                    ->limit($batchSize)
                    ->all();

                foreach ($entries as $entry) {
                    $this->_extractFromEntry($entry);
                    $totalProcessed++;

                    if ($progressCallback) {
                        $progressCallback($totalProcessed);
                    }
                }

                $offset += $batchSize;

                // Free memory
                gc_collect_cycles();
            }
        }

        return $this->_links;
    }

    /**
     * Extract links from a single entry.
     *
     * @return array<array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}>
     */
    public function extractFromEntry(Entry $entry): array
    {
        $this->_links = [];
        $this->_extractFromEntry($entry);
        return $this->_links;
    }

    private function _extractFromEntry(Entry $entry): void
    {
        $fieldLayout = $entry->getFieldLayout();
        if ($fieldLayout === null) {
            return;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $handle = $field->handle;

            try {
                $this->_extractFromField($entry, $field, $handle);
            } catch (\Throwable $e) {
                Craft::warning(
                    "Appleseed: Error extracting links from entry {$entry->id} field {$handle}: {$e->getMessage()}",
                    __METHOD__,
                );
            }
        }
    }

    private function _extractFromField(Entry $entry, mixed $field, string $handle): void
    {
        $value = $entry->getFieldValue($handle);

        // CKEditor / Rich Text fields -- have getRawContent() or serialize to string
        if (is_object($value) && method_exists($value, 'getRawContent')) {
            $html = $value->getRawContent();
            $this->_extractFromHtml($html, $entry, $handle);
            return;
        }

        // Link field
        if ($field instanceof LinkField) {
            if ($value !== null) {
                $url = is_object($value) && method_exists($value, 'getUrl') ? $value->getUrl() : (string) $value;
                $linkText = is_object($value) && method_exists($value, 'getText') ? $value->getText() : null;
                $this->_addLink($url, $entry, $handle, $linkText);
            }
            return;
        }

        // URL field
        if ($field instanceof UrlField) {
            if (!empty($value)) {
                $this->_addLink((string) $value, $entry, $handle);
            }
            return;
        }

        // Matrix fields (Craft 5 returns EntryQuery)
        if ($field instanceof MatrixField) {
            if ($value !== null) {
                $nestedEntries = $value->all();
                foreach ($nestedEntries as $nestedEntry) {
                    $this->_extractFromEntry($nestedEntry);
                }
            }
            return;
        }

        // Table fields
        if ($field instanceof TableField) {
            if (is_array($value)) {
                foreach ($value as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ($row as $cellValue) {
                        if (is_string($cellValue) && $this->_looksLikeUrl($cellValue)) {
                            $this->_addLink($cellValue, $entry, $handle);
                        }
                    }
                }
            }
            return;
        }

        // Fallback: if value is a string that contains HTML
        if (is_string($value) && str_contains($value, '<a ')) {
            $this->_extractFromHtml($value, $entry, $handle);
        }
    }

    private function _extractFromHtml(string $html, Entry $entry, string $fieldHandle): void
    {
        if (empty($html)) {
            return;
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<meta charset="utf-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($doc);

        // Extract <a href="...">
        $anchors = $xpath->query('//a[@href]');
        if ($anchors) {
            foreach ($anchors as $anchor) {
                $url = $anchor->getAttribute('href');
                $linkText = trim($anchor->textContent);
                $this->_addLink($url, $entry, $fieldHandle, $linkText ?: null);
            }
        }

        // Extract <img src="...">
        $images = $xpath->query('//img[@src]');
        if ($images) {
            foreach ($images as $img) {
                $url = $img->getAttribute('src');
                $alt = $img->getAttribute('alt');
                $this->_addLink($url, $entry, $fieldHandle, $alt ?: null);
            }
        }

        libxml_clear_errors();
    }

    private function _addLink(string $url, Entry $entry, string $fieldHandle, ?string $linkText = null): void
    {
        $url = trim($url);

        if (empty($url)) {
            return;
        }

        // Skip non-HTTP schemes
        if (preg_match('/^(mailto:|tel:|javascript:|#)/', $url)) {
            return;
        }

        // Resolve relative URLs against site base URL
        if (!preg_match('/^https?:\/\//i', $url)) {
            $baseUrl = rtrim(Craft::$app->getSites()->getSiteById($entry->siteId)?->getBaseUrl() ?? '', '/');
            if (str_starts_with($url, '/')) {
                $url = $baseUrl . $url;
            } else {
                $url = $baseUrl . '/' . $url;
            }
        }

        // Normalize: lowercase scheme and host
        $parsed = parse_url($url);
        if ($parsed && isset($parsed['host'])) {
            $normalized = strtolower($parsed['scheme'] ?? 'https') . '://' . strtolower($parsed['host']);
            if (isset($parsed['port'])) {
                $normalized .= ':' . $parsed['port'];
            }
            $normalized .= $parsed['path'] ?? '/';
            if (isset($parsed['query'])) {
                $normalized .= '?' . $parsed['query'];
            }
            $url = $normalized;
        }

        $this->_links[] = [
            'url' => $url,
            'entryId' => $entry->id,
            'siteId' => $entry->siteId,
            'fieldHandle' => $fieldHandle,
            'linkText' => $linkText ? mb_substr($linkText, 0, 500) : null,
        ];
    }

    private function _looksLikeUrl(string $value): bool
    {
        return (bool) preg_match('/^https?:\/\//i', trim($value));
    }
}
