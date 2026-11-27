<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Support\ScanType;

/**
 * Deciding when a scheduled scan is due, and queueing it.
 *
 * Two things ask: Laravel's scheduler, which runs `appleseed:scan:scheduled` hourly (once
 * `php artisan schedule:run` is in cron, or `schedule:work` is running), and control panel
 * requests after their response has gone out, for hosting without a scheduler (how Appleseed 5
 * did it). Either way the scan lock means two callers can't both start one.
 *
 * The schedule is measured from the last completed scan that read every entry (full, content or
 * incremental), so a scan of one saved entry never pushes it back, and it never cold-starts: until
 * a scan has completed there's nothing to schedule from.
 */
class Schedules
{
    /** How often control panel requests may even look, so the check costs one cache read. */
    private const string WEB_THROTTLE_KEY = 'appleseed:webScheduleCheck';

    public function __construct(
        private readonly Settings $settings,
        private readonly Scanner $scanner,
        private readonly Reporting $reporting,
    ) {}

    public function lastScan(): ?Scan
    {
        return $this->reporting->getLastCompletedScan(ScanType::CONTENT);
    }

    public function nextRunAt(): ?CarbonInterface
    {
        $interval = $this->settings->scheduleInterval();
        $last = $this->lastScan()?->completedAt;

        return $interval !== null && $last !== null ? $last->copy()->addSeconds($interval) : null;
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        $next = $this->nextRunAt();

        return $next !== null && $next->lessThanOrEqualTo($now ?? now());
    }

    /**
     * Queues the scheduled scan if one is due. Returns whether one was queued.
     */
    public function runIfDue(?CarbonInterface $now = null): bool
    {
        if (! $this->isDue($now)) {
            return false;
        }

        $type = $this->settings->scheduledScanType === ScanType::INCREMENTAL ? ScanType::INCREMENTAL : ScanType::FULL;

        // The scan lock refuses a second caller, and a scan that's already running or queued.
        if (! $this->scanner->queueScan($type)) {
            return false;
        }

        Log::channel(Plugin::LOG_CHANNEL)->info("Queued a scheduled {$type} scan.");

        return true;
    }

    /**
     * The control panel fallback: at most one look a minute, whatever the traffic.
     */
    public function runIfDueFromWeb(): bool
    {
        if ($this->settings->scheduleInterval() === null || ! Cache::add(self::WEB_THROTTLE_KEY, 1, 60)) {
            return false;
        }

        return $this->runIfDue();
    }
}
