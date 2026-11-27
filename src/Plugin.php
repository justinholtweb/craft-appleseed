<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Icons as CpIcons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Mcp\CapabilityRegistry;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Queue\JobProgress;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use JustinHoltWeb\Appleseed\Console\Commands\CheckUrlCommand;
use JustinHoltWeb\Appleseed\Console\Commands\LinksExportCommand;
use JustinHoltWeb\Appleseed\Console\Commands\LinksRecheckCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanCancelCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanEmailCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanEntryCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanPruneCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanScheduledCommand;
use JustinHoltWeb\Appleseed\Console\Commands\ScanStatusCommand;
use JustinHoltWeb\Appleseed\Events\ScanCompleted;
use JustinHoltWeb\Appleseed\Gql\GqlAccess;
use JustinHoltWeb\Appleseed\Gql\Queries\AppleseedQueries;
use JustinHoltWeb\Appleseed\Jobs\CheckLinkChunk;
use JustinHoltWeb\Appleseed\Listeners\AddEntrySidebar;
use JustinHoltWeb\Appleseed\Listeners\QueueEntryScan;
use JustinHoltWeb\Appleseed\Listeners\QueueScanWebhook;
use JustinHoltWeb\Appleseed\Listeners\SendScanNotifications;
use JustinHoltWeb\Appleseed\Mcp\AppleseedTools;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\LinkChecker;
use JustinHoltWeb\Appleseed\Services\LinkExtractor;
use JustinHoltWeb\Appleseed\Services\Notifier;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Services\Schedules;
use JustinHoltWeb\Appleseed\Services\Spider;
use JustinHoltWeb\Appleseed\Support\HostThrottle;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\ScanType;
use JustinHoltWeb\Appleseed\Widgets\BrokenLinksWidget;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Appleseed: a broken link checker for Craft CMS 6.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** View the dashboard, link details, CSV export, the widget and the entry sidebar row. */
    public const string PERMISSION_VIEW = 'appleseed-viewDashboard';

    /** Start scans and re-checks, cancel scans, ignore and un-ignore links. */
    public const string PERMISSION_SCAN = 'appleseed-runScans';

    /** See the settings (read-only; saving needs an admin). */
    public const string PERMISSION_SETTINGS = 'appleseed-manageSettings';

    /** The Laravel log channel Appleseed writes to (`storage/logs/appleseed-*.log` unless the site defines its own). */
    public const string LOG_CHANNEL = 'appleseed';

    /** Unchanged from 5.3.0: the tables are the same. */
    public string $schemaVersion = '1.2.0';

    /**
     * Sites upgrade from the last Appleseed 5 release, so `database/migrations/Install.php` can
     * describe the final schema.
     */
    public string $minVersionRequired = '5.3.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    protected array $styles = [
        __DIR__.'/../resources/css/appleseed.css' => 'css/appleseed.css',
    ];

    protected array $scripts = [
        __DIR__.'/../resources/js/appleseed.js' => 'js/appleseed.js',
    ];

    protected array $commands = [
        ScanCommand::class,
        ScanEntryCommand::class,
        ScanStatusCommand::class,
        ScanCancelCommand::class,
        ScanScheduledCommand::class,
        ScanPruneCommand::class,
        ScanEmailCommand::class,
        LinksExportCommand::class,
        LinksRecheckCommand::class,
        CheckUrlCommand::class,
    ];

    protected array $widgets = [
        BrokenLinksWidget::class,
    ];

    protected array $gqlQueries = [
        AppleseedQueries::class,
    ];

    protected array $events = [
        ScanCompleted::class => [SendScanNotifications::class, QueueScanWebhook::class],
        ElementSaved::class => QueueEntryScan::class,
        ElementMetadataResolving::class => AddEntrySidebar::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        // One of each per request (and per queue job), sharing one settings object.
        foreach ([HostThrottle::class, LinkExtractor::class, Spider::class, LinkChecker::class, Scanner::class, Reporting::class, Notifier::class, Schedules::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(CapabilityRegistry $capabilities): void
    {
        $this->app->bind(Settings::class, fn () => $this->getSettings());

        $this->registerLogChannel();

        // MCP: the tools are on Craft's own server (`php artisan craft:mcp:serve`, or HTTP).
        $capabilities->register(AppleseedTools::class);

        Event::listen(GqlSchemaComponentsResolving::class, function (GqlSchemaComponentsResolving $event): void {
            $event->queries[t('Appleseed', category: 'appleseed')] = [
                GqlAccess::SCOPE.':read' => ['label' => t('Query Appleseed links and scans (appleseedLinks, appleseedLink, appleseedLinkCount, appleseedSummary, appleseedScans, appleseedScan). Never available on the public schema.', category: 'appleseed')],
            ];
        });

        $this->trackBatchedJobs();

        // Hosting with no scheduler: after a control panel response has gone out, see whether a
        // scheduled scan is due. One cache read a minute at most; the scan itself is queued.
        if (! $this->app->runningInConsole()) {
            $this->app->terminating(function (): void {
                try {
                    if (request()->isCpRequest()) {
                        app(Schedules::class)->runIfDueFromWeb();
                    }
                } catch (Throwable $e) {
                    Log::channel(self::LOG_CHANNEL)->warning('The scheduled-scan check failed: '.$e->getMessage());
                }
            });
        }
    }

    /**
     * An `appleseed` log channel, unless the site defines its own in `config/logging.php` (to send
     * Appleseed's messages to Slack, Papertrail or a stack, say).
     */
    private function registerLogChannel(): void
    {
        if (config('logging.channels.'.self::LOG_CHANNEL) !== null) {
            return;
        }

        config(['logging.channels.'.self::LOG_CHANNEL => [
            'driver' => 'daily',
            'path' => storage_path('logs/appleseed.log'),
            'level' => 'info',
            'days' => 14,
            'replace_placeholders' => true,
        ]]);
    }

    /**
     * Gives each queued link-checking chunk a row in Craft's queue manager.
     *
     * The database queue inserts a batch's jobs in one statement, without the `JobQueued` event
     * Craft records jobs from, so the chunks would be invisible to the queue manager and to the
     * control panel's queue runner (`runQueueAutomatically`), which only runs when it sees a
     * pending row. A payload hook sees every job as it's serialised.
     */
    private function trackBatchedJobs(): void
    {
        Queue::createPayloadUsing(static function (?string $connection, ?string $queue, array $payload): array {
            if (($payload['data']['commandName'] ?? null) !== CheckLinkChunk::class || ! is_string($payload['uuid'] ?? null)) {
                return [];
            }

            try {
                $tracked = Cms::config()->trackedQueueNames;

                if (in_array($queue ?? Cms::config()->queueName, $tracked, true)) {
                    app(JobProgress::class)->queued($payload['uuid'], t('Appleseed: Checking links', category: 'appleseed'));
                }
            } catch (Throwable) {
                // Never stop a job being queued over the queue manager's bookkeeping.
            }

            return [];
        });
    }

    /**
     * Scheduled scans on Laravel's scheduler (`php artisan schedule:run` from cron, or
     * `schedule:work`): the command decides whether a scan is due, so hourly suits every frequency.
     * Old scans are pruned daily with `model:prune` (Scan is Prunable; `scanHistoryDays`).
     */
    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(ScanScheduledCommand::class)
            ->hourly()
            ->withoutOverlapping(120)
            ->when(fn () => $this->getSettings()->scanFrequency !== Settings::FREQUENCY_MANUAL)
            ->name('appleseed:scan:scheduled');

        $schedule->command('model:prune', ['--model' => [Scan::class]])
            ->daily()
            ->name('appleseed:prune-scans');
    }

    /**
     * The 5.x permission keys, unchanged: Craft 6 compares permissions case-insensitively, as
     * Craft 5 stored them (lowercased), so users and groups keep what they had.
     */
    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(key: self::PERMISSION_VIEW, label: t('View broken link dashboard', category: 'appleseed')),
            new Permission(key: self::PERMISSION_SCAN, label: t('Run link scans', category: 'appleseed')),
            new Permission(key: self::PERMISSION_SETTINGS, label: t('Manage Appleseed settings', category: 'appleseed'), info: t('Settings are shown read-only; saving them needs an admin.', category: 'appleseed')),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();
        $user = currentUser();

        if (! $item instanceof NavItem || $user === null) {
            return null;
        }

        // Only the screens this user can open.
        $subnav = [];

        if ($user->can(self::PERMISSION_VIEW)) {
            $subnav[] = new NavItem()->id('dashboard')->label(t('Dashboard', category: 'appleseed'))->href('appleseed/dashboard');
        }

        if ($user->isAdmin() || $user->can(self::PERMISSION_SETTINGS)) {
            $subnav[] = new NavItem()->id('settings')->label(t('Settings', category: 'appleseed'))->href('appleseed/settings');
        }

        if ($subnav === []) {
            return null;
        }

        $item->label(t('Appleseed', category: 'appleseed'))->href($subnav[0]->href)->subnav($subnav);

        // Broken links, so the nav reflects the site's state. Never worth an error page
        // (mid-install, before the tables exist).
        try {
            $broken = $user->can(self::PERMISSION_VIEW) ? app(Reporting::class)->getBrokenLinkCount() : 0;

            if ($broken > 0) {
                $item->badgeCount($broken)->badgeLabel(t('{count, plural, =1{# broken link} other{# broken links}}', ['count' => $broken], category: 'appleseed'));
            }
        } catch (Throwable) {
        }

        return $this->customiseNavItem($item, $this->getSettings());
    }

    /**
     * The sidebar label and icon from settings: label, then pasted SVG (sanitised every time it's
     * drawn), then an icon name. Craft's own Settings → Control Panel Navigation overrides win,
     * because Craft applies them afterwards. A bad value never takes the nav down.
     */
    public function customiseNavItem(NavItem $item, Settings $settings): NavItem
    {
        $label = trim((string) $settings->navLabel);

        if ($label !== '') {
            $item->label($label);
        }

        $svg = trim((string) $settings->navIconSvg);
        $icon = trim((string) $settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitised = Html::svg($svg, sanitize: true, namespace: true);

                if ($sanitised !== '') {
                    return $item->iconSvg($sanitised)->icon(null);
                }
            }

            if ($icon !== '' && IconSets::parse($icon) !== null) {
                // A custom set's icon (`set:name`) is drawn server-side.
                return $item->iconSvg(CpIcons::svg($icon))->icon(null);
            }

            if ($icon !== '' && Settings::iconExists($icon)) {
                return $item->icon($icon)->iconSvg(null);
            }
        } catch (Throwable $e) {
            Log::channel(self::LOG_CHANNEL)->warning('Appleseed couldn’t draw the custom nav icon: '.$e->getMessage());
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $statusOptions = LinkStatus::filterOptions();

        return Ui::make([
            Heading::make('appleseed-checking', t('Link checking', category: 'appleseed')),
            Field::make(t('Check external links', category: 'appleseed'), Lightswitch::make('checkExternalLinks'))
                ->instructions(t('Whether to check links pointing to other domains.', category: 'appleseed')),
            Field::make(t('Timeout (seconds)', category: 'appleseed'), Number::make('timeout')->min(1)->max(60)->size(5))
                ->instructions(t('How long to wait for each URL to answer.', category: 'appleseed')),
            Field::make(t('Max retries', category: 'appleseed'), Number::make('maxRetries')->min(1)->max(10)->size(5))
                ->instructions(t('Retries after a failed connection, with exponential backoff.', category: 'appleseed')),
            Field::make(t('Rate limit (requests/second)', category: 'appleseed'), Number::make('rateLimitPerSecond')->min(0.1)->max(100)->step(0.1)->size(5))
                ->instructions(t('The most requests a second to any one host, across every queue worker. Several hosts are checked at once.', category: 'appleseed')),
            Field::make(t('Scan batch size', category: 'appleseed'), Number::make('scanBatchSize')->min(1)->max(1000)->size(5))
                ->instructions(t('How many links each queued checking job takes. A scan’s jobs run as one Laravel batch, which several queue workers can share.', category: 'appleseed')),
            Field::make(t('User agent', category: 'appleseed'), Text::make('userAgent')->placeholder(Settings::DEFAULT_USER_AGENT))
                ->instructions(t('Sent with every request. Accepts an environment variable.', category: 'appleseed')),

            Heading::make('appleseed-spider', t('Spider', category: 'appleseed')),
            Field::make(t('Enable the spider', category: 'appleseed'), Lightswitch::make('spiderEnabled'))
                ->instructions(t('On full scans, crawl the rendered pages as well as reading entry fields.', category: 'appleseed')),
            Field::make(t('Max pages to spider', category: 'appleseed'), Number::make('maxPagesToSpider')->min(1)->max(10000)->size(6)),

            Heading::make('appleseed-schedule', t('Scheduling', category: 'appleseed')),
            Field::make(t('Scan frequency', category: 'appleseed'), Choice::make('scanFrequency')->options([
                ['label' => t('Manual only', category: 'appleseed'), 'value' => Settings::FREQUENCY_MANUAL],
                ['label' => t('Daily', category: 'appleseed'), 'value' => Settings::FREQUENCY_DAILY],
                ['label' => t('Weekly', category: 'appleseed'), 'value' => Settings::FREQUENCY_WEEKLY],
                ['label' => t('Monthly', category: 'appleseed'), 'value' => Settings::FREQUENCY_MONTHLY],
            ]))
                ->instructions(t('Scheduled scans start counting from your last completed scan, so run one from the dashboard to get the schedule going. Laravel’s scheduler runs `appleseed:scan:scheduled` hourly once `php artisan schedule:run` is in cron; without a scheduler, control panel visits queue a due scan instead.', category: 'appleseed')),
            Field::make(t('Scheduled scan type', category: 'appleseed'), Choice::make('scheduledScanType')->options([
                ['label' => t('Full scan', category: 'appleseed'), 'value' => ScanType::FULL],
                ['label' => t('Changed entries', category: 'appleseed'), 'value' => ScanType::INCREMENTAL],
            ]))
                ->instructions(t('“Changed entries” only reads entries updated since the last scan, which is much faster on a large site; run a full scan now and then to catch links that broke on their own.', category: 'appleseed')),
            Field::make(t('Scan on entry save', category: 'appleseed'), Lightswitch::make('scanOnEntrySave'))
                ->instructions(t('Check an entry’s links whenever it’s saved.', category: 'appleseed')),

            Heading::make('appleseed-notify', t('Notifications', category: 'appleseed'))
                ->description(t('Sent after a scan that crosses the threshold, except a scan of one saved entry.', category: 'appleseed')),
            Field::make(t('Notification emails', category: 'appleseed'), Text::make('notificationEmails')->placeholder('ops@example.com, $APPLESEED_EMAILS'))
                ->instructions(t('Comma-separated addresses, or an environment variable.', category: 'appleseed')),
            Field::make(t('Notification threshold', category: 'appleseed'), Number::make('notificationThreshold')->min(1)->size(5))
                ->instructions(t('The fewest broken links that sends a notification.', category: 'appleseed')),
            Field::make(t('Only newly broken links', category: 'appleseed'), Lightswitch::make('notifyOnlyNew'))
                ->instructions(t('Notify only when links broke since the scan before, not every time a scan finds links that were already broken. The threshold then counts newly broken links.', category: 'appleseed')),
            Field::make(t('Slack webhook URL', category: 'appleseed'), Text::make('slackWebhookUrl')->placeholder('$APPLESEED_SLACK_WEBHOOK'))
                ->instructions(t('A Slack incoming-webhook URL, on the same terms as the email. Keep the URL itself in `.env` and enter the environment variable here.', category: 'appleseed')),
            Field::make(t('Control panel notifications', category: 'appleseed'), Lightswitch::make('notifyCpUsers'))
                ->instructions(t('Also notify everyone who can run link scans, in the control panel.', category: 'appleseed')),
            Field::make(t('Scan webhook URL', category: 'appleseed'), Text::make('webhookUrl')->placeholder('$APPLESEED_WEBHOOK_URL'))
                ->instructions(t('Appleseed POSTs a JSON summary here after every completed scan (except scans of a single saved entry): the scan, the totals and up to 100 broken links. Sent from the queue, retried on failure.', category: 'appleseed')),
            Field::make(t('Webhook secret', category: 'appleseed'), Text::make('webhookSecret')->placeholder('$APPLESEED_WEBHOOK_SECRET'))
                ->instructions(t('Optional. Signs each body as `X-Appleseed-Signature: sha256=<HMAC>`. Use an environment variable.', category: 'appleseed')),
            Field::make(t('Email layout template', category: 'appleseed'), Text::make('emailLayoutTemplate')->placeholder('_emails/layout'))
                ->instructions(t('Optional site template that wraps the report email. It gets the report HTML as `content`; output it with the `raw` filter.', category: 'appleseed')),

            Heading::make('appleseed-content', t('Content', category: 'appleseed')),
            Field::make(t('Excluded fields', category: 'appleseed'), Textarea::make('excludedFields')->rows(3)->monospace())
                ->instructions(t('Field handles whose links are never collected, one per line or comma-separated. Applies inside Matrix fields too.', category: 'appleseed')),
            Field::make(t('Ignore patterns', category: 'appleseed'), Textarea::make('ignorePatterns')->rows(5)->monospace()->placeholder('/^https:\/\/example\.com\//'))
                ->instructions(t('One regular expression per line. Matching URLs are never requested: they aren’t checked, and the spider won’t crawl them.', category: 'appleseed')),

            Heading::make('appleseed-cp', t('Control panel', category: 'appleseed'))
                ->description(t('How Appleseed appears in the control panel. An override set in Settings → Control Panel Navigation takes precedence.', category: 'appleseed')),
            Field::make(t('Default status filter', category: 'appleseed'), Choice::make('defaultStatusFilter')->options($statusOptions))
                ->instructions(t('The status the dashboard’s results are filtered by when you first open it.', category: 'appleseed')),
            Field::make(t('Sidebar label', category: 'appleseed'), Text::make('navLabel')->placeholder('Appleseed')->maxLength(64))
                ->instructions(t('Leave empty for “Appleseed”.', category: 'appleseed')),
            Field::make(t('Sidebar icon', category: 'appleseed'), IconPicker::make('navIcon'))
                ->instructions(t('Leave empty for Appleseed’s own icon.', category: 'appleseed')),
            Field::make(t('Custom SVG icon', category: 'appleseed'), Textarea::make('navIconSvg')->rows(4)->monospace())
                ->instructions(t('Optional. Paste one `<svg>` element to use instead of the icon above. It’s sanitised every time it’s drawn; a single-colour icon using `currentColor` matches the rest of the sidebar.', category: 'appleseed')),

            Heading::make('appleseed-housekeeping', t('Housekeeping', category: 'appleseed')),
            Field::make(t('Days of scan history', category: 'appleseed'), Number::make('scanHistoryDays')->min(1)->max(3650)->size(5))
                ->instructions(t('Finished scans older than this are deleted daily by Laravel’s `model:prune`. Links and their results are kept.', category: 'appleseed')),
        ]);
    }
}
