<?php

namespace justinholtweb\appleseed\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\appleseed\assets\AppleseedAsset;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\Plugin;

/**
 * "Broken Links" dashboard widget: how many links are broken, when the site was last scanned, and
 * the links that broke most recently. Only offered to people who can view Appleseed's dashboard.
 *
 * @since 5.3.0
 */
class BrokenLinksWidget extends Widget
{
    /** How many recently broken links to list. 0 shows just the counts. */
    public int $limit = 5;

    /** @var int|string|null Only links found on this site. The settings form posts '' for all sites. */
    public int|string|null $siteId = null;

    public static function displayName(): string
    {
        return Craft::t('appleseed', 'Broken Links');
    }

    public static function icon(): ?string
    {
        return 'link-slash';
    }

    public static function isSelectable(): bool
    {
        return parent::isSelectable() && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 0, 'max' => 25];
        $rules[] = [['siteId'], 'integer'];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('appleseed/_widgets/broken-links/settings', [
            'widget' => $this,
            'sites' => Craft::$app->getIsMultiSite() ? Craft::$app->getSites()->getAllSites() : [],
        ]);
    }

    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return Craft::t('appleseed', 'You don’t have permission to view Appleseed’s results.');
        }

        $reporting = Plugin::getInstance()->reporting;
        $siteId = (int)$this->siteId ?: null;
        $links = [];

        if ($this->limit > 0) {
            $links = $reporting->linkQuery(['status' => LinkStatus::BROKEN, 'siteId' => $siteId])
                ->select(['l.*'])
                ->orderBy(['l.firstBrokenAt' => SORT_DESC, 'l.id' => SORT_DESC])
                ->limit($this->limit)
                ->all();
            $links = $reporting->attachSources($links, $siteId);
        }

        Craft::$app->getView()->registerAssetBundle(AppleseedAsset::class);

        return Craft::$app->getView()->renderTemplate('appleseed/_widgets/broken-links/body', [
            'summary' => $reporting->getDashboardSummary($siteId),
            'lastScan' => $reporting->getLastCompletedScan(),
            'links' => $links,
            'siteId' => $siteId,
        ]);
    }
}
