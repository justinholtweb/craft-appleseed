<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Listeners;

use JustinHoltWeb\Appleseed\Events\ScanCompleted;
use JustinHoltWeb\Appleseed\Jobs\SendScanWebhook;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\Notifier;
use JustinHoltWeb\Appleseed\Support\ScanType;

/**
 * Queues the signed scan webhook after every completed scan (except a scan of one saved entry),
 * when one is configured. The body is built now, so it describes this scan even if the job is
 * retried later.
 */
readonly class QueueScanWebhook
{
    public function __construct(
        private Settings $settings,
        private Notifier $notifier,
    ) {}

    public function handle(ScanCompleted $event): void
    {
        if ($event->scan->type === ScanType::ENTRY || $this->settings->getWebhookUrlParsed() === '') {
            return;
        }

        SendScanWebhook::dispatch($this->notifier->webhookPayload($event->scan));
    }
}
