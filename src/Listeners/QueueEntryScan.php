<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Listeners;

use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\Scanner;

/**
 * "Scan on entry save": queues a scan of a section entry when it's saved. Drafts, revisions,
 * propagating saves and nested entries are skipped (their owner is saved too, and links are
 * recorded against it).
 */
readonly class QueueEntryScan
{
    public function __construct(
        private Settings $settings,
        private Scanner $scanner,
    ) {}

    public function handle(ElementSaved $event): void
    {
        $entry = $event->element;

        if (! $this->settings->scanOnEntrySave || ! $entry instanceof Entry) {
            return;
        }

        if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->propagating || $entry->sectionId === null || $entry->id === null) {
            return;
        }

        $this->scanner->queueEntryScan($entry->id, $entry->siteId);
    }
}
