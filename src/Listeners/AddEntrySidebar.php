<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Listeners;

use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Services\Reporting;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * The "Links" row in the entry editor's sidebar: the entry's broken links, redirects and blocked
 * links from the last scan that read it, with "Check this entry again".
 *
 * Craft 6's entry editor has no sidebar-HTML hook; the metadata rows are what both its editors
 * show. The value is a closure, so nothing is queried unless the editor draws it.
 */
readonly class AddEntrySidebar
{
    public function __construct(
        private Reporting $reporting,
    ) {}

    public function handle(ElementMetadataResolving $event): void
    {
        $entry = $event->element;
        $user = currentUser();

        // Nested entries are reported against their owner
        if (! $entry instanceof Entry || $entry->id === null || $entry->getIsRevision() || $entry->sectionId === null
            || $user === null || ! $user->can(Plugin::PERMISSION_VIEW)) {
            return;
        }

        $canScan = $user->can(Plugin::PERMISSION_SCAN);

        $event->metadata[t('Links', category: 'appleseed')] = function () use ($entry, $canScan): string|false {
            try {
                $entryId = (int) $entry->getCanonicalId();

                return template('appleseed/_entry-links', [
                    'entryId' => $entryId,
                    'siteId' => $entry->siteId,
                    'links' => $this->reporting->getEntryLinks($entryId, $entry->siteId),
                    'canScan' => $canScan,
                ], TemplateMode::Cp);
            } catch (Throwable $e) {
                // Never take the entry editor down over a sidebar row (mid-update, before the
                // migration has run, say).
                Log::channel(Plugin::LOG_CHANNEL)->warning("Couldn’t draw the entry sidebar: {$e->getMessage()}");

                return false;
            }
        };
    }
}
