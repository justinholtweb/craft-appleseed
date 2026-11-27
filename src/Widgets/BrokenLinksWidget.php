<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Widgets;

use CraftCms\Cms\Dashboard\Widgets\Widget;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\View\TemplateMode;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * "Broken Links" on Craft's dashboard: how many links are broken, when the site was last scanned,
 * and the links that broke most recently, optionally for one site. Only for people who can view
 * Appleseed's dashboard.
 */
class BrokenLinksWidget extends Widget
{
    /** How many recently broken links to list. 0 shows just the counts. */
    public int $limit = 5;

    /** Only links found on this site. Null (or the settings form's '') for all. */
    public int|string|null $siteId = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Broken Links', category: 'appleseed');
    }

    #[Override]
    public static function icon(): string
    {
        return 'link-slash';
    }

    #[Override]
    public static function isSelectable(): bool
    {
        return (bool) currentUser()?->can(Plugin::PERMISSION_VIEW);
    }

    #[Override]
    public function getRules(): array
    {
        return [
            'limit' => ['integer', 'min:0', 'max:25'],
            'siteId' => ['nullable', 'integer'],
        ];
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $fields = [
            Field::make(t('Links to list', category: 'appleseed'))
                ->instructions(t('The most recently broken links. 0 shows just the counts.', category: 'appleseed'))
                ->control(Number::make('limit')->value($this->limit)->min(0)->max(25)->size(3)),
        ];

        if (Sites::isMultiSite()) {
            $options = [['label' => t('All sites', category: 'appleseed'), 'value' => '']];

            /** @var Site $site */
            foreach (Sites::getAllSites() as $site) {
                $options[] = ['label' => $site->getName(), 'value' => (string) $site->id];
            }

            $fields[] = Field::make(t('Site', category: 'appleseed'))
                ->control(Choice::make('siteId')->value((string) $this->siteId)->options($options));
        }

        return Ui::make($fields);
    }

    public function component(): ?string
    {
        return 'craft:html-widget';
    }

    /** @return array{html: string} */
    #[Override]
    public function props(): array
    {
        if (! currentUser()?->can(Plugin::PERMISSION_VIEW)) {
            return ['html' => ''];
        }

        try {
            $reporting = app(Reporting::class);
            $siteId = (int) $this->siteId ?: null;
            $links = [];

            if ($this->limit > 0) {
                $links = $reporting->withSources(
                    $reporting->linkQuery(['status' => LinkStatus::BROKEN, 'siteId' => $siteId])
                        ->orderByDesc('firstBrokenAt')
                        ->orderByDesc('id')
                        ->limit($this->limit)
                        ->get(),
                    $siteId,
                );
            }

            return ['html' => template('appleseed/widgets/_broken-links', [
                'summary' => $reporting->getDashboardSummary($siteId),
                'lastScan' => $reporting->getLastCompletedScan(),
                'links' => $links,
                'siteId' => $siteId,
            ], TemplateMode::Cp)];
        } catch (Throwable) {
            // During an interrupted install the tables may not exist yet.
            return ['html' => ''];
        }
    }
}
