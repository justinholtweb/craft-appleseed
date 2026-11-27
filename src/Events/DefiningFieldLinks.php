<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Events;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;

/**
 * Dispatched for every custom field Appleseed reads, before its own handling.
 * (`LinkExtractor::EVENT_DEFINE_FIELD_LINKS` / `DefineFieldLinksEvent` in Appleseed 5.)
 *
 * Add links for a field type Appleseed doesn't know about, or replace its handling of one:
 *
 * ```php
 * Event::listen(DefiningFieldLinks::class, function (DefiningFieldLinks $event) {
 *     if ($event->field instanceof MyField) {
 *         foreach ($event->value->getLinks() as $link) {
 *             $event->links[] = ['url' => $link->url, 'linkText' => $link->label];
 *         }
 *         $event->skipDefault = true; // skip Appleseed's own handling of this field
 *     }
 * });
 * ```
 *
 * Relative URLs are resolved against the entry's site, and every URL is normalised and filtered the
 * same way as Appleseed's own.
 */
class DefiningFieldLinks
{
    /**
     * @param  Entry  $entry  The entry being read. For a field inside a Matrix field, the nested entry.
     * @param  Entry  $owner  The top-level entry the links are recorded against.
     * @param  mixed  $value  The field's value.
     * @param  array<array<string, mixed>>  $links  Links to record for the field: `['url' => string, 'linkText' => ?string]`.
     * @param  bool  $skipDefault  Set to true to skip Appleseed's own handling of the field.
     */
    public function __construct(
        public Entry $entry,
        public Entry $owner,
        public FieldInterface $field,
        public mixed $value = null,
        public array $links = [],
        public bool $skipDefault = false,
    ) {}
}
