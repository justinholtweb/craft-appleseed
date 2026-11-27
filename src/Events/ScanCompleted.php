<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Events;

use JustinHoltWeb\Appleseed\Models\Scan;

/**
 * Dispatched once a scan has completed and its tallies are final, before notifications go out.
 * (`Scanner::EVENT_AFTER_SCAN` in Appleseed 5.)
 *
 * Appleseed's own email, Slack and webhook notifications listen for it; so can anything else:
 *
 * ```php
 * Event::listen(ScanCompleted::class, function (ScanCompleted $event) {
 *     // $event->scan->brokenCount, ->newBrokenCount, ->type…
 * });
 * ```
 *
 * Not dispatched for a cancelled or failed scan.
 */
class ScanCompleted
{
    public function __construct(
        public Scan $scan,
    ) {}
}
