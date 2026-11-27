<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\Schedules;

/**
 * Queues the scheduled scan if one is due. Laravel's scheduler runs this hourly (see
 * Plugin::schedule()); it can also be run from cron directly.
 */
#[Signature('appleseed:scan:scheduled')]
#[Description('Queues the scheduled Appleseed scan, if one is due.')]
class ScanScheduledCommand extends Command
{
    use CraftCommand;

    public function handle(Settings $settings, Schedules $schedules): int
    {
        if ($settings->scheduleInterval() === null) {
            $this->line('<fg=gray>Scheduled scans are off (scan frequency: manual).</>');

            return self::SUCCESS;
        }

        if ($schedules->lastScan() === null) {
            $this->line('<fg=gray>No scan has completed yet: run one to start the schedule.</>');

            return self::SUCCESS;
        }

        if (! $schedules->isDue()) {
            $this->line(sprintf('<fg=gray>Not due yet. Next scan: %s</>', $schedules->nextRunAt()?->format('Y-m-d H:i') ?? 'unknown'));

            return self::SUCCESS;
        }

        if (! $schedules->runIfDue()) {
            $this->line('<fg=gray>A scan is already running or queued.</>');

            return self::SUCCESS;
        }

        $this->components->info('Scheduled scan queued.');

        return self::SUCCESS;
    }
}
