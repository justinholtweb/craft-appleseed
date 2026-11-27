<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Link as LinkField;
use CraftCms\Cms\Field\Table as TableField;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Appleseed\Events\DefiningFieldLinks;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Support\LinkText;
use JustinHoltWeb\Appleseed\Support\LinkUrl;
use Throwable;

/**
 * Reads links out of entries' fields: rich text (CKEditor and anything with `getRawContent()`),
 * Link, Table, Matrix and CKEditor nested entries, and third-party link fields whose values know
 * their URL (`getUrl()`, or `getLinks()` of things that do).
 *
 * Nested entries are read through their owner and recorded against it, with the field path
 * (`blocks.body`), so every link points at an entry an editor can open.
 */
class LinkExtractor
{
    /** How deep nested entries (Matrix inside Matrix…) are followed. */
    private const int MAX_DEPTH = 8;

    /** @var list<array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}> */
    private array $links = [];

    /**
     * Entries read by the last extraction, keyed `"{siteId}:{entryId}"`, including those with no
     * links, so the scanner can clear sources for links that were removed from them.
     *
     * @var array<string, array{entryId: int, siteId: int}>
     */
    private array $processed = [];

    /** @var list<string> */
    private array $excludedFields = [];

    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * Extract links from every live section entry on every site.
     *
     * @param  (callable(int): void)|null  $progress  Called with how many entries have been read.
     * @param  list<int>|null  $sectionIds  Only these sections.
     * @param  DateTimeInterface|null  $changedSince  Only entries updated since then.
     * @return list<array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}>
     */
    public function extractAllLinks(?callable $progress = null, ?array $sectionIds = null, ?DateTimeInterface $changedSince = null): array
    {
        $this->reset();
        $total = 0;

        foreach (Sites::getAllSites() as $site) {
            $query = Entry::find()->siteId($site->id)->status('live');

            // Section entries only. Nested entries (Matrix, CKEditor) are read through their owner,
            // so their links are recorded against the entry an editor can open.
            if (! empty($sectionIds)) {
                $query->sectionId($sectionIds);
            } else {
                $query->section('*');
            }

            if ($changedSince !== null) {
                $query->dateUpdated('>= '.$changedSince->format(DateTimeInterface::ATOM));
            }

            /** @var Entry $entry */
            foreach ($query->lazyById(100) as $entry) {
                $this->extractFromEntryInto($entry, $entry, '', 0);
                $total++;

                if ($progress !== null && $total % 10 === 0) {
                    $progress($total);
                }
            }
        }

        if ($progress !== null) {
            $progress($total);
        }

        return $this->links;
    }

    /**
     * Extract links from one entry.
     *
     * @return list<array{url: string, entryId: int, siteId: int, fieldHandle: string, linkText: string|null}>
     */
    public function extractFromEntry(Entry $entry): array
    {
        $this->reset();
        $this->extractFromEntryInto($entry, $entry, '', 0);

        return $this->links;
    }

    /**
     * The entries the last extraction read.
     *
     * @return list<array{entryId: int, siteId: int}>
     */
    public function getProcessedEntries(): array
    {
        return array_values($this->processed);
    }

    private function reset(): void
    {
        $this->links = [];
        $this->processed = [];
        $this->excludedFields = $this->settings->getExcludedFieldsArray();
    }

    /**
     * @param  Entry  $entry  The entry whose fields are read.
     * @param  Entry  $owner  The top-level entry links are recorded against.
     * @param  string  $path  The field path inside the owner, e.g. `blocks.`, for nested entries.
     */
    private function extractFromEntryInto(Entry $entry, Entry $owner, string $path, int $depth): void
    {
        if ($entry === $owner) {
            $this->processed["{$owner->siteId}:{$owner->id}"] = ['entryId' => (int) $owner->id, 'siteId' => (int) $owner->siteId];
        }

        $fieldLayout = $entry->getFieldLayout();

        if ($fieldLayout === null) {
            return;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $handle = (string) $field->handle;

            if ($handle === '' || in_array($handle, $this->excludedFields, true)) {
                continue;
            }

            try {
                $this->extractFromField($entry, $owner, $field, $path.$handle, $depth);
            } catch (Throwable $e) {
                Log::channel(Plugin::LOG_CHANNEL)->warning("Couldn’t read links from entry {$entry->id}, field {$handle}: {$e->getMessage()}");
            }
        }
    }

    private function extractFromField(Entry $entry, Entry $owner, FieldInterface $field, string $handle, int $depth): void
    {
        $value = $entry->getFieldValue((string) $field->handle);

        if (Event::hasListeners(DefiningFieldLinks::class)) {
            event($event = new DefiningFieldLinks($entry, $owner, $field, $value));

            foreach ($event->links as $link) {
                if (is_string($link['url'] ?? null) && $link['url'] !== '') {
                    $text = $link['linkText'] ?? null;
                    $this->addLink($link['url'], $owner, $handle, is_string($text) ? $text : null);
                }
            }

            if ($event->skipDefault) {
                return;
            }
        }

        // Rich text (CKEditor and other HTML fields). The *parsed* content, so element reference
        // tags (`{entry:123@1:url||…}`) are resolved to real URLs rather than read as literal hrefs.
        if (is_object($value) && method_exists($value, 'getRawContent')) {
            $html = method_exists($value, 'getParsedContent')
                ? (string) $value->getParsedContent()
                : (string) $value->getRawContent();

            $this->extractFromHtml($html, $owner, $handle);

            // CKEditor keeps nested entries alongside the HTML
            if ($depth < self::MAX_DEPTH && method_exists($value, 'getEntries')) {
                $this->extractFromNested($value->getEntries(), $owner, "$handle.", $depth);
            }

            return;
        }

        if ($field instanceof LinkField) {
            if ($value !== null) {
                $url = is_object($value) && method_exists($value, 'getUrl') ? $value->getUrl() : $value;
                $text = is_object($value) && method_exists($value, 'getLabel') ? $value->getLabel() : null;

                if (is_string($url)) {
                    $this->addLink($url, $owner, $handle, is_string($text) ? $text : null);
                }
            }

            return;
        }

        if ($field instanceof TableField) {
            foreach (is_iterable($value) ? $value : [] as $row) {
                foreach (is_array($row) ? $row : [] as $cell) {
                    if (is_string($cell) && preg_match('/^https?:\/\//i', trim($cell))) {
                        $this->addLink($cell, $owner, $handle);
                    }
                }
            }

            return;
        }

        // Matrix and other nested-entry fields: an entry query, or the entries themselves.
        if ($value instanceof ElementQueryInterface || $this->isEntryCollection($value)) {
            if ($depth < self::MAX_DEPTH) {
                $this->extractFromNested($value, $owner, "$handle.", $depth);
            }

            return;
        }

        // Third-party link fields (Hyper, Typed Link and the like): a value, or a collection of
        // values, that knows its own URL.
        if ($this->extractFromLinkObjects($value, $owner, $handle)) {
            return;
        }

        // Fallback: a string that holds HTML
        if (is_string($value) && str_contains($value, '<a ')) {
            $this->extractFromHtml($value, $owner, $handle);
        }
    }

    private function extractFromNested(mixed $nested, Entry $owner, string $path, int $depth): void
    {
        $entries = match (true) {
            $nested instanceof ElementQueryInterface => $nested->status(null)->all(),
            is_iterable($nested) => $nested,
            default => [],
        };

        foreach ($entries as $nestedEntry) {
            if ($nestedEntry instanceof Entry && $nestedEntry->enabled) {
                $this->extractFromEntryInto($nestedEntry, $owner, $path, $depth + 1);
            }
        }
    }

    private function isEntryCollection(mixed $value): bool
    {
        if (! is_iterable($value) || is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            return $item instanceof Entry;
        }

        return false;
    }

    /**
     * Records links from a value that has `getUrl()`, or an iterable of them (`getLinks()`).
     * Returns whether the value was one.
     */
    private function extractFromLinkObjects(mixed $value, Entry $owner, string $handle): bool
    {
        if (! is_object($value) || $value instanceof ElementQueryInterface) {
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
            if (! is_object($item) || ! method_exists($item, 'getUrl')) {
                continue;
            }

            $url = $item->getUrl();

            if (! is_string($url) || $url === '') {
                continue;
            }

            $text = null;

            foreach (['getText', 'getLinkText', 'getLabel'] as $method) {
                if (method_exists($item, $method)) {
                    $candidate = $item->$method();
                    $text = is_string($candidate) ? $candidate : null;
                    break;
                }
            }

            $this->addLink($url, $owner, $handle, $text);
        }

        return true;
    }

    private function extractFromHtml(string $html, Entry $entry, string $fieldHandle): void
    {
        foreach (self::linksInHtml($html) as $link) {
            $this->addLink($link['url'], $entry, $fieldHandle, $link['linkText']);
        }
    }

    /**
     * The `<a href>` and `<img src>` URLs in some HTML, as written.
     *
     * @return list<array{url: string, linkText: string|null, nofollow: bool}>
     */
    public static function linksInHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<meta charset="utf-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        $xpath = new DOMXPath($doc);
        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if ($anchor instanceof DOMElement) {
                $rel = preg_split('/\s+/', strtolower($anchor->getAttribute('rel'))) ?: [];
                $links[] = [
                    'url' => trim($anchor->getAttribute('href')),
                    'linkText' => LinkText::normalize($anchor->textContent),
                    'nofollow' => in_array('nofollow', $rel, true),
                ];
            }
        }

        foreach ($xpath->query('//img[@src]') ?: [] as $img) {
            if ($img instanceof DOMElement) {
                $links[] = [
                    'url' => trim($img->getAttribute('src')),
                    'linkText' => LinkText::normalize($img->getAttribute('alt')),
                    'nofollow' => false,
                ];
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $links;
    }

    private function addLink(string $url, Entry $entry, string $fieldHandle, ?string $linkText = null): void
    {
        $url = $this->normalize($url, (int) $entry->siteId);

        if ($url === null) {
            return;
        }

        $this->links[] = [
            'url' => $url,
            'entryId' => (int) $entry->id,
            'siteId' => (int) $entry->siteId,
            'fieldHandle' => $fieldHandle,
            'linkText' => LinkText::normalize($linkText),
        ];
    }

    /**
     * A URL as Appleseed stores it, or null when it isn't one to check: resolved against the site,
     * scheme and host lowercased, credentials and fragment dropped. `mailto:`, `tel:` and the like,
     * and reference tags whose element is gone, are null.
     */
    public function normalize(string $url, int $siteId): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Resolve any element reference tags that made it through unparsed
        if (str_contains($url, '{')) {
            $url = trim(Elements::parseRefs($url, $siteId));

            // parseRefs leaves unresolvable tags in place
            if ($url === '' || preg_match(LinkUrl::REF_TAG_PATTERN, $url)) {
                return null;
            }
        }

        if (preg_match('/^(mailto:|tel:|sms:|javascript:|data:|#)/i', $url)) {
            return null;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if (! preg_match('/^https?:\/\//i', $url)) {
            // Any other scheme (ftp:, skype:…) isn't something to request
            if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
                return null;
            }

            $baseUrl = rtrim((string) Sites::getSiteById($siteId)?->getBaseUrl(), '/');
            $url = $baseUrl.(str_starts_with($url, '/') ? $url : '/'.$url);
        }

        $parsed = parse_url(LinkUrl::stripCredentials($url));

        if (! is_array($parsed) || ! isset($parsed['host'])) {
            return null;
        }

        $normalized = strtolower($parsed['scheme'] ?? 'https').'://'.strtolower($parsed['host']);

        if (isset($parsed['port'])) {
            $normalized .= ':'.$parsed['port'];
        }

        $normalized .= $parsed['path'] ?? '/';

        if (isset($parsed['query'])) {
            $normalized .= '?'.$parsed['query'];
        }

        return $normalized;
    }
}
