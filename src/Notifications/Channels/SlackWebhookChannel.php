<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Posts a notification to a Slack incoming webhook. Route it on demand:
 * `Notification::route(SlackWebhookChannel::class, $url)`. The notification provides
 * `toSlackWebhook($notifiable): array` (Slack's message JSON, e.g. `['text' => '…']`).
 *
 * Laravel's own Slack channel is a separate package (`laravel/slack-notification-channel`), and an
 * incoming webhook needs nothing more than a POST.
 */
class SlackWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor(self::class, $notification)
            : null;

        if (! is_string($url) || $url === '' || ! method_exists($notification, 'toSlackWebhook')) {
            return;
        }

        Http::timeout(10)->connectTimeout(5)->asJson()->post($url, $notification->toSlackWebhook($notifiable))->throw();
    }
}
