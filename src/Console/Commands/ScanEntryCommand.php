<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Throwable;

#[Signature('appleseed:scan:entry
    {--entry-id= : The entry’s ID}
    {--id= : Same as --entry-id}
    {--site-id= : Only this site}
    {--queue : Queue the scan instead of running it here}')]
#[Description('Scans one entry’s links.')]
#[Aliases(['appleseed/scan/entry'])]
class ScanEntryCommand extends Command
{
    use CraftCommand;

    public function handle(Scanner $scanner): int
    {
        $entryId = (int) ($this->option('entry-id') ?? $this->option('id'));
        $siteId = $this->option('site-id') !== null ? (int) $this->option('site-id') : null;

        if ($entryId <= 0) {
            $this->components->error('Give an entry ID with --entry-id=<id>.');

            return self::INVALID;
        }

        if ($this->option('queue')) {
            $scanner->queueEntryScan($entryId, $siteId);
            $this->components->info('Entry scan queued.');

            return self::SUCCESS;
        }

        try {
            $scan = $scanner->runScan(ScanType::ENTRY, $entryId, $siteId);
        } catch (Throwable $e) {
            $this->components->error('Scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Entry #%d: %d links found, %d checked, %d broken.', $entryId, $scan->totalLinksFound, $scan->totalLinksChecked, $scan->brokenCount));

        return self::SUCCESS;
    }
}
