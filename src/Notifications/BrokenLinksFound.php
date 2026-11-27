<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use JustinHoltWeb\Appleseed\Mail\ScanReportMail;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Notifications\Channels\SlackWebhookChannel;
use JustinHoltWeb\Appleseed\Services\Notifier;
use JustinHoltWeb\Appleseed\Services\Reporting;

/**
 * A scan found enough broken links to tell someone: the report by email, and a summary in Slack.
 *
 * Sent on demand ({@see Notifier}): one notifiable per email address, and one for the Slack
 * webhook, so a broken mailer never costs the Slack post. Queued, and retried.
 */
class BrokenLinksFound extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Scan $scan,
    ) {}

    /**
     * Whichever of mail and Slack this notifiable has a route for.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return [];
        }

        return array_values(array_filter([
            $notifiable->routeNotificationFor('mail', $this) ? 'mail' : null,
            $notifiable->routeNotificationFor(SlackWebhookChannel::class, $this) ? SlackWebhookChannel::class : null,
        ]));
    }

    public function toMail(object $notifiable): ScanReportMail
    {
        $reporting = app(Reporting::class);
        $recipient = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail', $this) : null;

        $mail = new ScanReportMail($reporting->reportSubject($this->scan), $reporting->renderScanReportEmail($this->scan));

        return is_string($recipient) || is_array($recipient) ? $mail->to($recipient) : $mail;
    }

    /**
     * @return array{text: string}
     */
    public function toSlackWebhook(object $notifiable): array
    {
        return ['text' => app(Notifier::class)->slackText($this->scan)];
    }
}
