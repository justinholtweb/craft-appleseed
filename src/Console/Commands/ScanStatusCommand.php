<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\Presenter;

#[Signature('appleseed:scan:status {--json : Print the status as JSON}')]
#[Description('Shows link totals, the running scan, recent scans and the worst hosts.')]
#[Aliases(['appleseed/scan/status'])]
class ScanStatusCommand extends Command
{
    use CraftCommand;

    public function handle(Reporting $reporting, Scanner $scanner): int
    {
        $summary = $reporting->getDashboardSummary();
        $running = $reporting->getRunningScan();
        $scans = $reporting->getScans(10);
        $hosts = $reporting->getWorstHosts(5);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'summary' => $summary,
                'runningScan' => $running !== null ? Presenter::scan($running) + ['progressPercent' => $running->progressPercent()] : null,
                'queued' => $running === null && $scanner->isQueued(),
                'scans' => array_map(fn (Scan $scan) => Presenter::scan($scan), $scans),
                'worstHosts' => $hosts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<options=bold>Links</>');

        foreach ($summary as $label => $count) {
            $this->components->twoColumnDetail($label, (string) $count);
        }

        if ($running !== null) {
            $this->newLine();
            $this->components->warn(sprintf('Running: #%d (%s), %d of %d links checked', $running->id, $running->type, $running->totalLinksChecked, $running->totalLinksFound));
        } elseif ($scanner->isQueued()) {
            $this->newLine();
            $this->components->warn('A scan is queued, waiting for a queue worker.');
        }

        $this->newLine();
        $this->table(['#', 'Type', 'Status', 'Links', 'Broken', 'New', 'When'], array_map(fn (Scan $scan) => [
            $scan->id,
            $scan->type,
            $scan->status,
            $scan->totalLinksFound,
            $scan->brokenCount,
            $scan->newBrokenCount,
            Presenter::date($scan->completedAt ?? $scan->startedAt) ?? '',
        ], $scans));

        if ($hosts !== []) {
            $this->components->twoColumnDetail('<options=bold>Hosts with the most broken links</>');

            foreach ($hosts as $host) {
                $this->components->twoColumnDetail($host['host'], (string) $host['broken']);
            }
        }

        return self::SUCCESS;
    }
}
