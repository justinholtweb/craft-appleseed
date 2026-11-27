<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Link as LinkField;
use craft\fields\Matrix as MatrixField;
use craft\fields\Table as TableField;
use craft\fields\Url as UrlField;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use justinholtweb\appleseed\events\DefineFieldLinksEvent;
use justinholtweb\appleseed\helpers\LinkText;
use justinholtweb\appleseed\helpers\LinkUrl;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;

class LinkExtractor extends Component
{
    /**
     * @event DefineFieldLinksEvent Triggered for every custom field read, before Appleseed's own
     * handling. Add links for other field types, or set `skipDefault` to replace Appleseed's handling.
     * @since 5.3.0
     */
    public const EVENT_DEFINE_FIELD_LINKS = 'defineFieldLinks';

    /** @var string Matches an element reference tag, e.g. `{entry:123@1:url||https://example.com/page}`. */
    private const REF_TAG_PATTERN = '/\{\w+\:[^\}]+\}/';

    /** How deep nested entries (Matrix inside Matrix…) are followed. */
    private const MAX_DEPTH = 8;

    /**
     * Discovered links: [['url' => string, 'entryId' => int, 'siteId' => int, 'fieldHandle' => string, 'linkText' => string|null]]
     */
    private array $_links = [];

    /**
     * Entries read by the last extraction, keyed `"{siteId}:{entryId}"` — including those with no
     * links, so the scanner can clear sources for links that were removed from them.
     *
     * @var array<string, array{entryId: int, siteId: int}>
     */
    private array $_processed = [];

    /** @var string[] */
    private array $_excludedFields = [];

    /**
     * Extract all links from all entries across all sites.
     *
     * @param int[]|null $sectionIds Optional section IDs to limit extraction to.
     * @param DateTimeInterface|null $changedSince Only entries updated since then.
     * @return array<array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}>
     */
    public function extractAllLinks(?callable $progressCallback = null, ?array $sectionIds = null, ?DateTimeInterface $changedSince = null): array
    {
        $this->_reset();

        $sites = Craft::$app->getSites()->getAllSites();
        $totalProcessed = 0;

        foreach ($sites as $site) {
            $query = Entry::find()
                ->siteId($site->id)
                ->status('live')
                ->orderBy(['elements.id' => SORT_ASC])
                ->limit(null);

            // Section entries only. Nested entries (Matrix, CKEditor) are read through their owner,
            // so their links are recorded against the entry an editor can open.
            if (!empty($sectionIds)) {
                $query->sectionId($sectionIds);
            } else {
                $query->section('*');
            }

            if ($changedSince !== null) {
                $query->dateUpdated('>= ' . $changedSince->format(DateTimeInterface::ATOM));
            }

            foreach ($query->each(100) as $entry) {
                /** @var Entry $entry */
                $this->_extractFromEntry($entry, $entry, '', 0);
                $totalProcessed++;

                if ($progressCallback && $totalProcessed % 10 === 0) {
                    $progressCallback($totalProcessed);
                }
            }

            gc_collect_cycles();
        }

        if ($progressCallback) {
            $progressCallback($totalProcessed);
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
        $this->_reset();
        $this->_extractFromEntry($entry, $entry, '', 0);

        return $this->_links;
    }

    /**
     * The entries the last extraction read.
     *
     * @return array<array{entryId: int, siteId: int}>
     */
    public function getProcessedEntries(): array
    {
        return array_values($this->_processed);
    }

    private function _reset(): void
    {
        $this->_links = [];
        $this->_processed = [];

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $this->_excludedFields = $settings->getExcludedFieldsArray();
    }

    /**
     * @param Entry $entry The entry whose fields are read.
     * @param Entry $owner The top-level entry links are recorded against.
     * @param string $path The field path inside the owner, e.g. `blocks.`, for nested entries.
     */
    private function _extractFromEntry(Entry $entry, Entry $owner, string $path, int $depth): void
    {
        if ($entry === $owner) {
            $this->_processed["{$owner->siteId}:{$owner->id}"] = ['entryId' => (int)$owner->id, 'siteId' => (int)$owner->siteId];
        }

        $fieldLayout = $entry->getFieldLayout();
        if ($fieldLayout === null) {
            return;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $handle = $field->handle;

            if (in_array($handle, $this->_excludedFields, true)) {
                continue;
            }

            try {
                $this->_extractFromField($entry, $owner, $field, $path . $handle, $depth);
            } catch (\Throwable $e) {
                Craft::warning(
                    "Appleseed: Error extracting links from entry {$entry->id} field {$handle}: {$e->getMessage()}",
                    __METHOD__,
                );
            }
        }
    }

    private function _extractFromField(Entry $entry, Entry $owner, FieldInterface $field, string $handle, int $depth): void
    {
        $value = $entry->getFieldValue($field->handle);

        if ($this->hasEventHandlers(self::EVENT_DEFINE_FIELD_LINKS)) {
            $event = new DefineFieldLinksEvent([
                'entry' => $entry,
                'owner' => $owner,
                'field' => $field,
                'value' => $value,
            ]);
            $this->trigger(self::EVENT_DEFINE_FIELD_LINKS, $event);

            foreach ($event->links as $link) {
                if (is_string($link['url'] ?? null) && $link['url'] !== '') {
                    $this->_addLink($link['url'], $owner, $handle, $link['linkText'] ?? null);
                }
            }

            if ($event->skipDefault) {
                return;
            }
        }

        // CKEditor / Rich Text fields -- have getRawContent() or serialize to string.
        // Use the *parsed* content so element reference tags (`{entry:123@1:url||...}`)
        // are resolved to real URLs rather than being treated as literal hrefs.
        if (is_object($value) && method_exists($value, 'getRawContent')) {
            if (method_exists($value, 'getParsedContent')) {
                $html = (string) $value->getParsedContent();
            } elseif (method_exists($value, '__toString')) {
                $html = (string) $value;
            } else {
                $html = (string) $value->getRawContent();
            }

            $this->_extractFromHtml($html, $owner, $handle);

            // CKEditor 4+ keeps nested entries alongside the HTML
            if ($depth < self::MAX_DEPTH && method_exists($value, 'getEntries')) {
                $nested = $value->getEntries();
                foreach (is_object($nested) && method_exists($nested, 'all') ? $nested->all() : [] as $nestedEntry) {
                    if ($nestedEntry instanceof Entry && $nestedEntry->enabled) {
                        $this->_extractFromEntry($nestedEntry, $owner, "$handle.", $depth + 1);
                    }
                }
            }
            return;
        }

        // Link field
        if ($field instanceof LinkField) {
            if ($value !== null) {
                $url = is_object($value) && method_exists($value, 'getUrl') ? $value->getUrl() : (string) $value;
                $linkText = is_object($value) && method_exists($value, 'getText') ? $value->getText() : null;
                if (is_string($url)) {
                    $this->_addLink($url, $owner, $handle, $linkText);
                }
            }
            return;
        }

        // URL field
        if ($field instanceof UrlField) {
            if (!empty($value)) {
                $this->_addLink((string) $value, $owner, $handle);
            }
            return;
        }

        // Matrix fields (Craft 5 returns EntryQuery)
        if ($field instanceof MatrixField) {
            if ($value !== null && $depth < self::MAX_DEPTH) {
                $nestedEntries = is_object($value) && method_exists($value, 'all') ? $value->status(null)->all() : (array)$value;
                foreach ($nestedEntries as $nestedEntry) {
                    if ($nestedEntry instanceof Entry && $nestedEntry->enabled) {
                        $this->_extractFromEntry($nestedEntry, $owner, "$handle.", $depth + 1);
                    }
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
                            $this->_addLink($cellValue, $owner, $handle);
                        }
                    }
                }
            }
            return;
        }

        // Third-party link fields (Hyper, Typed Link and the like): a value — or a collection of
        // values — that knows its own URL.
        if ($this->_extractFromLinkObjects($value, $owner, $handle)) {
            return;
        }

        // Fallback: if value is a string that contains HTML
        if (is_string($value) && str_contains($value, '<a ')) {
            $this->_extractFromHtml($value, $owner, $handle);
        }
    }

    /**
     * Records links from a value that has `getUrl()`, or an iterable of them. Returns whether the
     * value was one.
     */
    private function _extractFromLinkObjects(mixed $value, Entry $owner, string $handle): bool
    {
        if (!is_object($value) || $value instanceof \craft\elements\db\ElementQueryInterface) {
            return false;
        }

        $items = method_exists($value, 'getUrl') ? [$value] : null;

        if ($items === null && method_exists($value, 'getLinks')) {
            $links = $value->getLinks();
            $items = is_iterable($links) ? $links : null;
        }

        if ($items === null) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item, 'getUrl')) {
                continue;
            }

            $url = $item->getUrl();
            if (!is_string($url) || $url === '') {
                continue;
            }

            $text = null;
            foreach (['getText', 'getLinkText'] as $method) {
                if (method_exists($item, $method)) {
                    $candidate = $item->$method();
                    $text = is_string($candidate) ? $candidate : null;
                    break;
                }
            }

            $this->_addLink($url, $owner, $handle, $text);
        }

        return true;
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
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $url = $anchor->getAttribute('href');
                $linkText = trim($anchor->textContent);
                $this->_addLink($url, $entry, $fieldHandle, $linkText ?: null);
            }
        }

        // Extract <img src="...">
        $images = $xpath->query('//img[@src]');
        if ($images) {
            foreach ($images as $img) {
                if (!$img instanceof DOMElement) {
                    continue;
                }
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

        // Resolve any element reference tags that made it through unparsed
        if (str_contains($url, '{')) {
            $resolved = $this->_resolveRefTags($url, $entry->siteId);
            if ($resolved === null) {
                return;
            }
            $url = $resolved;
        }

        // Skip non-HTTP schemes
        if (preg_match('/^(mailto:|tel:|sms:|javascript:|data:|#)/i', $url)) {
            return;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        // Resolve relative URLs against site base URL
        if (!preg_match('/^https?:\/\//i', $url)) {
            // Any other scheme (ftp:, skype:…) isn't something to request
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
                return;
            }

            $baseUrl = rtrim(Craft::$app->getSites()->getSiteById($entry->siteId)?->getBaseUrl() ?? '', '/');
            if (str_starts_with($url, '/')) {
                $url = $baseUrl . $url;
            } else {
                $url = $baseUrl . '/' . $url;
            }
        }

        // Normalize: lowercase scheme and host; drop credentials and the fragment
        $parsed = parse_url(LinkUrl::stripCredentials($url));
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
            'linkText' => LinkText::normalize($linkText),
        ];
    }

    /**
     * Resolve element reference tags (e.g. `{entry:123@1:url||https://example.com/page}`)
     * to real URLs.
     *
     * Rich text values normally arrive already parsed, but reference tags can still turn
     * up in plain text, Table, and URL field values. Returns null when a tag can't be
     * resolved -- the referenced element is gone and there's no fallback URL, so there is
     * nothing to check.
     */
    private function _resolveRefTags(string $url, ?int $siteId): ?string
    {
        $url = trim(Craft::$app->getElements()->parseRefs($url, $siteId));

        // parseRefs leaves unresolvable tags in place
        if (preg_match(self::REF_TAG_PATTERN, $url)) {
            return null;
        }

        return $url !== '' ? $url : null;
    }

    private function _looksLikeUrl(string $value): bool
    {
        return (bool) preg_match('/^https?:\/\//i', trim($value));
    }
}
