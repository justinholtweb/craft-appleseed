<?php

namespace justinholtweb\appleseed;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\services\Dashboard;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\appleseed\assets\AppleseedAsset;
use justinholtweb\appleseed\gql\GqlAccess;
use justinholtweb\appleseed\gql\queries\AppleseedQueries;
use justinholtweb\appleseed\gql\types\AppleseedLinkSourceType;
use justinholtweb\appleseed\gql\types\AppleseedLinkType;
use justinholtweb\appleseed\gql\types\AppleseedScanType;
use justinholtweb\appleseed\gql\types\AppleseedSummaryType;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\services\LinkChecker;
use justinholtweb\appleseed\services\LinkExtractor;
use justinholtweb\appleseed\services\Notifier;
use justinholtweb\appleseed\services\Reporting;
use justinholtweb\appleseed\services\Scanner;
use justinholtweb\appleseed\services\Spider;
use justinholtweb\appleseed\widgets\BrokenLinksWidget;
use yii\base\Event;

/**
 * Appleseed - Broken Link Checker for Craft CMS
 *
 * @property LinkExtractor $linkExtractor
 * @property LinkChecker $linkChecker
 * @property Spider $spider
 * @property Scanner $scanner
 * @property Reporting $reporting
 * @property Notifier $notifier
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** View the dashboard, link details, CSV export, the widget and the entry sidebar panel. */
    public const PERMISSION_VIEW = 'appleseed-viewDashboard';

    /** Start scans and re-checks, cancel scans, ignore and un-ignore links. */
    public const PERMISSION_SCAN = 'appleseed-runScans';

    /** See the settings (read-only; saving needs an admin). */
    public const PERMISSION_SETTINGS = 'appleseed-manageSettings';

    /** The first Craft version whose MCP server Appleseed's tools are written for. */
    public const MCP_MIN_CRAFT_VERSION = '5.12.1';

    public string $schemaVersion = '1.2.0';
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
                'notifier' => Notifier::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Registered on every request: Craft discards permission names it doesn't know when it saves
        // them, so registering only for CP requests let a console save — `project-config/apply`
        // included — strip Appleseed's permissions from users and groups.
        $this->_registerPermissions();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
            $this->_registerEntrySidebar();
        }

        $this->_registerWidgets();
        $this->_registerGraphQl();
        $this->_registerMcp();
        $this->_registerEntryListeners();
        $this->_checkScheduledScan();
    }

    /**
     * Whether this Craft has the MCP server Appleseed's tools need (5.12.1 or later).
     */
    public static function mcpAvailable(?string $craftVersion = null): bool
    {
        return version_compare($craftVersion ?? Craft::$app->getVersion(), self::MCP_MIN_CRAFT_VERSION, '>=')
            && class_exists(\craft\services\Mcp::class)
            && class_exists(\Mcp\Capability\Attribute\McpTool::class);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('appleseed', 'Appleseed');

        // Only the screens this user can open.
        $user = Craft::$app->getUser();
        $item['subnav'] = [];

        if ($user->checkPermission(self::PERMISSION_VIEW)) {
            $item['subnav']['dashboard'] = ['label' => Craft::t('appleseed', 'Dashboard'), 'url' => 'appleseed/dashboard'];
        }

        if ($user->checkPermission(self::PERMISSION_SETTINGS)) {
            $item['subnav']['settings'] = ['label' => Craft::t('appleseed', 'Settings'), 'url' => 'appleseed/settings'];
        }

        $item = $this->customiseNavItem($item, $this->getSettings());

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

    /**
     * The sidebar label and icon from settings: pasted SVG (sanitised every time it's drawn), then
     * an icon name, then Appleseed's own icon. Craft's own Settings → Control Panel Navigation
     * overrides still win, because Craft applies them afterwards. A bad value never breaks the nav.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function customiseNavItem(array $item, Settings $settings): array
    {
        $label = trim((string)$settings->navLabel);

        if ($label !== '') {
            $item['label'] = $label;
        }

        $svg = trim((string)$settings->navIconSvg);
        $icon = trim((string)$settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitised = Html::svg($svg, true, true, true);

                if ($sanitised !== '') {
                    $item['icon'] = $sanitised;

                    return $item;
                }
            }

            if ($icon !== '' && Settings::iconExists($icon)) {
                $item['icon'] = $icon;
            }
        } catch (\Throwable $e) {
            Craft::warning(sprintf('Appleseed couldn’t draw the custom nav icon: %s', $e->getMessage()), __METHOD__);
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
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
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
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('appleseed', 'Appleseed'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('appleseed', 'View broken link dashboard'),
                        ],
                        self::PERMISSION_SCAN => [
                            'label' => Craft::t('appleseed', 'Run link scans'),
                        ],
                        self::PERMISSION_SETTINGS => [
                            'label' => Craft::t('appleseed', 'Manage Appleseed settings'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = BrokenLinksWidget::class;
        });
    }

    /**
     * An "Appleseed" panel in the entry editor's sidebar: the entry's broken links and redirects,
     * from the most recent scan that read it, with a button to check it again.
     */
    private function _registerEntrySidebar(): void
    {
        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $user = Craft::$app->getUser();

            if (!$entry->id || $entry->getIsRevision() || !$user->checkPermission(self::PERMISSION_VIEW)) {
                return;
            }

            // Nested entries are reported against their owner
            if ($entry->sectionId === null) {
                return;
            }

            try {
                $entryId = (int)$entry->getCanonicalId();
                $links = $this->reporting->getEntryLinks($entryId, $entry->siteId);

                $view = Craft::$app->getView();
                $view->registerAssetBundle(AppleseedAsset::class);
                $event->html .= $view->renderTemplate('appleseed/_entry-sidebar', [
                    'entryId' => $entryId,
                    'siteId' => $entry->siteId,
                    'links' => $links,
                    'canScan' => $user->checkPermission(self::PERMISSION_SCAN),
                ], \craft\web\View::TEMPLATE_MODE_CP);
            } catch (\Throwable $e) {
                // Never take the entry editor down over a sidebar panel (e.g. mid-update, before
                // the migration has run).
                Craft::warning("Appleseed couldn’t draw the entry sidebar: {$e->getMessage()}", __METHOD__);
            }
        });
    }

    /**
     * Read-only GraphQL queries for links and scans, behind the `appleseedLinks:read` scope and
     * never on the public schema.
     */
    private function _registerGraphQl(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function(RegisterGqlTypesEvent $event) {
            $event->types[] = AppleseedLinkType::class;
            $event->types[] = AppleseedLinkSourceType::class;
            $event->types[] = AppleseedScanType::class;
            $event->types[] = AppleseedSummaryType::class;
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, AppleseedQueries::getQueries());
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $event->queries[Craft::t('appleseed', 'Appleseed')] = [
                GqlAccess::SCOPE . ':read' => [
                    'label' => Craft::t('appleseed', 'Query Appleseed links and scans (appleseedLinks, appleseedLink, appleseedLinkCount, appleseedSummary, appleseedScans, appleseedScan). Never available on the public schema.'),
                ],
            ];
        });
    }

    /**
     * MCP tools, on Craft 5.12.1 and later. Nothing here touches an MCP class on older Craft,
     * where the server doesn't exist.
     */
    private function _registerMcp(): void
    {
        if (!self::mcpAvailable()) {
            return;
        }

        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = mcp\AppleseedTools::class;
            },
        );
    }

    private function _registerEntryListeners(): void
    {
        $settings = $this->getSettings();

        if (!$settings->scanOnEntrySave) {
            return;
        }

        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;

                // Skip drafts, revisions, propagating entries, and nested entries (their owner is
                // saved too, and links are recorded against it)
                if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->propagating || $entry->sectionId === null) {
                    return;
                }

                $this->scanner->queueEntryScan((int)$entry->id, $entry->siteId);
            }
        );
    }

    private function _checkScheduledScan(): void
    {
        // Only check on CP web requests to avoid overhead on every console/frontend request
        if (!Craft::$app->getRequest()->getIsCpRequest() || Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

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
            // Measured from the last scan that read the whole site: a scan of one saved entry
            // mustn't push the schedule back.
            $lastScan = $this->reporting->getLastCompletedScan([ScanType::FULL, ScanType::DATABASE, ScanType::INCREMENTAL]);
            $runningScan = $this->reporting->getRunningScan();
        } catch (\Throwable) {
            return; // Table may not exist yet
        }

        // Don't push if a scan is already running
        if ($runningScan) {
            return;
        }

        // Never cold-start. Until a scan has been run manually (or from the console),
        // there's no baseline to schedule from, and queueing one off the back of an
        // install or update isn't something anyone asked for.
        if ($lastScan === null || $lastScan->completedAt === null) {
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

        $isDue = (time() - strtotime($lastScan->completedAt)) >= $interval;

        if ($isDue) {
            $type = $settings->scheduledScanType === ScanType::INCREMENTAL ? ScanType::INCREMENTAL : ScanType::FULL;
            $this->scanner->queueScan($type);
            // Cache for 1 hour to avoid duplicate pushes
            Craft::$app->getCache()->set($cacheKey, true, 3600);
        }
    }
}
