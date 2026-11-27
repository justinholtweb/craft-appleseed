<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Jobs;

use CraftCms\Cms\Queue\Job;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Override;

use function CraftCms\Cms\t;

/**
 * Scans one entry: reads its fields again and checks every link in them. Queued after a save
 * (`scanOnEntrySave`) and by the entry sidebar's "Check this entry again". (`ScanEntryJob` in 5.)
 */
class ScanEntry extends Job
{
    public int $tries = 1;

    public function __construct(
        public int $entryId,
        public ?int $siteId = null,
    ) {
        parent::__construct();
    }

    public function handle(Scanner $scanner): void
    {
        $scanner->runScan(
            ScanType::ENTRY,
            $this->entryId,
            $this->siteId,
            progress: fn (string $message, float $fraction) => $this->setProgress((int) max(0, min(100, round($fraction * 100))), $message),
        );
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('Appleseed: Scanning entry #{id} for broken links', ['id' => $this->entryId], category: 'appleseed');
    }
}
