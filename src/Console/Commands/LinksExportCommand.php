<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Support\LinkStatus;

#[Signature('appleseed:links:export
    {--status= : Only this status (`broken` for every broken status, or `ignored`)}
    {--host= : Only links to this host}
    {--site-id= : Only links found on this site}
    {--file= : Write the CSV here instead of to stdout}')]
#[Description('Exports links as CSV, one row per place each was found.')]
#[Aliases(['appleseed/links/export'])]
class LinksExportCommand extends Command
{
    use CraftCommand;

    public function handle(Reporting $reporting): int
    {
        $status = $this->option('status');

        if ($status !== null && ! in_array($status, LinkStatus::ALL, true)) {
            $this->components->error('Unknown status. Use one of: '.implode(', ', LinkStatus::ALL));

            return self::INVALID;
        }

        $csv = $reporting->generateCsv(array_filter([
            'status' => $status,
            'host' => $this->option('host'),
            'siteId' => $this->option('site-id') !== null ? (int) $this->option('site-id') : null,
        ]));

        if (($file = $this->option('file')) !== null) {
            file_put_contents((string) $file, $csv);
            $this->line("Written to {$file}");
        } else {
            $this->output->write($csv);
        }

        return self::SUCCESS;
    }
}
