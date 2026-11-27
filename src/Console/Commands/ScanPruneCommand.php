<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\Scanner;

/**
 * Deletes old scan history now. Laravel's `model:prune` does the same daily from the scheduler.
 */
#[Signature('appleseed:scan:prune {--days= : Keep this many days (default: the scanHistoryDays setting)}')]
#[Description('Deletes scan history older than --days. Links and their results are kept.')]
#[Aliases(['appleseed/scan/prune'])]
class ScanPruneCommand extends Command
{
    use CraftCommand;

    public function handle(Scanner $scanner, Settings $settings): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : $settings->scanHistoryDays;
        $deleted = $scanner->pruneScans($days);

        $this->line("Deleted {$deleted} scan(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
