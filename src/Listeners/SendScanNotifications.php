<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Listeners;

use JustinHoltWeb\Appleseed\Events\ScanCompleted;
use JustinHoltWeb\Appleseed\Services\Notifier;

/**
 * Email, Slack and control panel notifications after a scan that crosses the threshold.
 */
readonly class SendScanNotifications
{
    public function __construct(
        private Notifier $notifier,
    ) {}

    public function handle(ScanCompleted $event): void
    {
        $this->notifier->scanCompleted($event->scan);
    }
}
