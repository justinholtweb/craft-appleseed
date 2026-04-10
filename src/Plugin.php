<?php

namespace justinholtweb\appleseed;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\web\UrlManager;
use craft\services\UserPermissions;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\services\LinkExtractor;
use justinholtweb\appleseed\services\LinkChecker;
use justinholtweb\appleseed\services\Spider;
use justinholtweb\appleseed\services\Scanner;
use justinholtweb\appleseed\services\Reporting;
use justinholtweb\appleseed\jobs\ScanJob;
use justinholtweb\appleseed\jobs\ScanEntryJob;
use yii\base\Event;

/**
 * Appleseed - Broken Link Checker for Craft CMS
 *
 * @property LinkExtractor $linkExtractor
 * @property LinkChecker $linkChecker
 * @property Spider $spider
 * @property Scanner $scanner
 * @property Reporting $reporting
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'linkExtractor' => LinkExtractor::class,
                'linkChecker' => LinkChecker::class,
                'spider' => Spider::class,
                'scanner' => Scanner::class,
                'reporting' => Reporting::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
            $this->_registerPermissions();
        }

        $this->_registerEntryListeners();
        $this->_checkScheduledScan();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('appleseed', 'Appleseed');

        $item['subnav'] = [
            'dashboard' => ['label' => Craft::t('appleseed', 'Dashboard'), 'url' => 'appleseed/dashboard'],
            'settings' => ['label' => Craft::t('appleseed', 'Settings'), 'url' => 'appleseed/settings'],
        ];

        // Badge count of broken links
        try {
            $brokenCount = $this->reporting->getBrokenLinkCount();
            if ($brokenCount > 0) {
                $item['badgeCount'] = $brokenCount;
            }
        } catch (\Throwable) {
            // Table may not exist yet
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('appleseed/settings/_fields', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['appleseed'] = 'appleseed/dashboard/index';
                $event->rules['appleseed/dashboard'] = 'appleseed/dashboard/index';
                $event->rules['appleseed/dashboard/detail/<linkId:\d+>'] = 'appleseed/dashboard/detail';
                $event->rules['appleseed/settings'] = 'appleseed/settings/index';
                $event->rules['appleseed/preview-email'] = 'appleseed/dashboard/preview-email';
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('appleseed', 'Appleseed'),
                    'permissions' => [
                        'appleseed-viewDashboard' => [
                            'label' => Craft::t('appleseed', 'View broken link dashboard'),
                        ],
                        'appleseed-runScans' => [
                            'label' => Craft::t('appleseed', 'Run link scans'),
                        ],
                        'appleseed-manageSettings' => [
                            'label' => Craft::t('appleseed', 'Manage Appleseed settings'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerEntryListeners(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (!$settings->scanOnEntrySave) {
            return;
        }

        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;

                // Skip drafts, revisions, and propagating entries
                if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->propagating) {
                    return;
                }

                Craft::$app->getQueue()->push(new ScanEntryJob([
                    'entryId' => $entry->id,
                    'siteId' => $entry->siteId,
                ]));
            }
        );
    }

    private function _checkScheduledScan(): void
    {
        // Only check on CP web requests to avoid overhead on every console/frontend request
        if (!Craft::$app->getRequest()->getIsCpRequest() || Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();

        if ($settings->scanFrequency === 'manual') {
            return;
        }

        // Prevent pushing duplicate scan jobs on every CP page load
        $cacheKey = 'appleseed_schedule_check';
        if (Craft::$app->getCache()->get($cacheKey)) {
            return;
        }

        try {
            $lastScan = $this->reporting->getLastCompletedScan();
            $runningScan = $this->reporting->getRunningScan();
        } catch (\Throwable) {
            return; // Table may not exist yet
        }

        // Don't push if a scan is already running
        if ($runningScan) {
            return;
        }

        $intervalMap = [
            'daily' => 86400,
            'weekly' => 604800,
            'monthly' => 2592000,
        ];

        $interval = $intervalMap[$settings->scanFrequency] ?? null;
        if ($interval === null) {
            return;
        }

        $isDue = $lastScan === null
            || (time() - strtotime($lastScan->completedAt)) >= $interval;

        if ($isDue) {
            Craft::$app->getQueue()->push(new ScanJob());
            // Cache for 1 hour to avoid duplicate pushes
            Craft::$app->getCache()->set($cacheKey, true, 3600);
        }
    }
}
