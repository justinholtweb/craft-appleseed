<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use JustinHoltWeb\Appleseed\Listeners\QueueScanWebhook;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Notifications\BrokenLinksFound;
use JustinHoltWeb\Appleseed\Notifications\BrokenLinksNotice;
use JustinHoltWeb\Appleseed\Notifications\Channels\SlackWebhookChannel;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Support\Presenter;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * Telling people about a finished scan: email, Slack and control panel notifications when it
 * crosses the threshold, and the content of the webhook (sent after every scan by
 * {@see QueueScanWebhook}).
 *
 * Scans of a single entry (after it's saved) never notify: an email for every save that touched a
 * broken link is noise. Each channel is tried on its own and never throws.
 */
class Notifier
{
    /** Broken links listed in a Slack message. */
    public const int SLACK_LINK_LIMIT = 10;

    /** Broken links included in a webhook body. */
    public const int WEBHOOK_LINK_LIMIT = 100;

    public function __construct(
        private readonly Settings $settings,
        private readonly Reporting $reporting,
    ) {}

    /**
     * Sends whatever the settings ask for, if this scan warrants it. Returns whether anything went.
     */
    public function scanCompleted(Scan $scan): bool
    {
        if ($scan->type === ScanType::ENTRY || ! $this->shouldAlert($scan)) {
            return false;
        }

        $sent = false;

        foreach ($this->settings->getNotificationEmailsArray() as $email) {
            $sent = $this->attempt('email', fn () => Notification::route('mail', $email)->notify(new BrokenLinksFound($scan))) || $sent;
        }

        if (($slack = $this->settings->getSlackWebhookUrlParsed()) !== '') {
            $sent = $this->attempt('Slack', fn () => Notification::route(SlackWebhookChannel::class, $slack)->notify(new BrokenLinksFound($scan))) || $sent;
        }

        if ($this->settings->notifyCpUsers) {
            $sent = $this->attempt('control panel', function () use ($scan): void {
                $users = User::find()->can(Plugin::PERMISSION_SCAN)->status(User::STATUS_ACTIVE)->all();

                if ($users !== []) {
                    Notification::send($users, new BrokenLinksNotice($scan, $this->headline($scan)));
                }
            }) || $sent;
        }

        return $sent;
    }

    /**
     * Whether a scan's results cross the notification threshold: broken links, or with "only newly
     * broken links" on, links that broke during this scan.
     */
    public function shouldAlert(Scan $scan): bool
    {
        $count = $this->settings->notifyOnlyNew ? $scan->newBrokenCount : $scan->brokenCount;

        return $count > 0 && $count >= max(1, $this->settings->notificationThreshold);
    }

    public function headline(Scan $scan): string
    {
        return $this->settings->notifyOnlyNew
            ? t('{count, plural, =1{# link} other{# links}} broke since the last scan ({total} broken in all).', ['count' => $scan->newBrokenCount, 'total' => $scan->brokenCount], category: 'appleseed')
            : t('{count, plural, =1{# broken link} other{# broken links}} found.', ['count' => $scan->brokenCount], category: 'appleseed');
    }

    /**
     * The Slack message: the headline, up to ten broken links, and a link to the dashboard.
     */
    public function slackText(Scan $scan): string
    {
        $lines = ['*Appleseed:* '.$this->headline($scan)];

        foreach ($this->reporting->brokenLinksFor($scan, self::SLACK_LINK_LIMIT, $this->settings->notifyOnlyNew) as $link) {
            $code = $link->statusCode !== null ? " ({$link->statusCode})" : '';
            $lines[] = '• <'.Presenter::linkCpUrl($link->id).'|'.self::slackEscape($link->url).'>'.$code;
        }

        $lines[] = '<'.cp_url('appleseed/dashboard', ['status' => 'broken']).'|'.t('Open the dashboard', category: 'appleseed').'>';

        return implode("\n", $lines);
    }

    /**
     * The webhook body: the scan, the totals, and up to 100 broken links. The same shape as
     * Appleseed 5.3's.
     *
     * @return array<string, mixed>
     */
    public function webhookPayload(Scan $scan): array
    {
        return [
            'event' => 'scan.completed',
            'site' => Sites::getPrimarySite()->getName(),
            'dashboardUrl' => cp_url('appleseed/dashboard'),
            'scan' => Presenter::scan($scan),
            'summary' => $this->reporting->getDashboardSummary(),
            'brokenLinks' => array_map(
                fn (Link $link) => Presenter::link($link, false) + ['cpUrl' => Presenter::linkCpUrl($link->id)],
                $this->reporting->brokenLinksFor($scan, self::WEBHOOK_LINK_LIMIT, false),
            ),
        ];
    }

    private static function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '%7C'], $text);
    }

    /**
     * @param  callable(): mixed  $send
     */
    private function attempt(string $channel, callable $send): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            // A Slack URL is a secret, and HTTP client messages include it.
            $message = str_replace($this->settings->getSlackWebhookUrlParsed() ?: "\0", '[url]', $e->getMessage());
            Log::channel(Plugin::LOG_CHANNEL)->warning("Couldn’t send the scan notification by {$channel}: {$message}");

            return false;
        }
    }
}
