<?php

namespace justinholtweb\appleseed\events;

use craft\base\FieldInterface;
use craft\elements\Entry;
use yii\base\Event;

/**
 * Triggered for every custom field Appleseed reads, before its own handling.
 *
 * Add links for a field type Appleseed doesn't know about, or replace its handling of one:
 *
 * ```php
 * Event::on(LinkExtractor::class, LinkExtractor::EVENT_DEFINE_FIELD_LINKS, function(DefineFieldLinksEvent $event) {
 *     if ($event->field instanceof MyField) {
 *         foreach ($event->value->getLinks() as $link) {
 *             $event->links[] = ['url' => $link->url, 'linkText' => $link->label];
 *         }
 *         $event->skipDefault = true; // skip Appleseed's own handling of this field
 *     }
 * });
 * ```
 *
 * Relative URLs are resolved against the entry's site, and every URL is normalised and filtered
 * the same way as Appleseed's own.
 */
class DefineFieldLinksEvent extends Event
{
    /** The entry being read. For a field inside a Matrix field, the nested entry. */
    public Entry $entry;

    /** The top-level entry the links are recorded against. */
    public Entry $owner;

    public FieldInterface $field;

    /** The field's value. */
    public mixed $value = null;

    /** @var array<array<string, mixed>> Links to record for the field: `['url' => string, 'linkText' => ?string]`. */
    public array $links = [];

    /**
     * Set to true to skip Appleseed's own handling of the field. (Not `$handled`, which would stop
     * the other handlers of this event from running.)
     */
    public bool $skipDefault = false;
}
