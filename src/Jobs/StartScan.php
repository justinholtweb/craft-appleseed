<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Jobs;

use CraftCms\Cms\Queue\Job;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Discovers a scan's links (entry fields, and the spider on a full scan), then hands checking to a
 * `Bus::batch()` of {@see CheckLinkChunk} jobs. (`ScanJob` in Appleseed 5.)
 */
class StartScan extends Job
{
    /** Crawling a large site takes a while. */
    public int $timeout = 3600;

    /** A failed discovery would fail the same way straight away. */
    public int $tries = 1;

    /**
     * @param  string  $type  One of {@see ScanType::STARTABLE}. Ignored when `sectionIds` is set.
     * @param  list<int>|null  $sectionIds
     * @param  string|null  $lockOwner  The scan lock taken when the scan was queued.
     */
    public function __construct(
        public string $type = ScanType::FULL,
        public ?array $sectionIds = null,
        public ?string $lockOwner = null,
    ) {
        parent::__construct();
    }

    public function handle(Scanner $scanner): void
    {
        $progress = fn (string $message, float $fraction) => $this->setProgress((int) max(0, min(100, round($fraction * 100))), $message);

        try {
            $scan = ! empty($this->sectionIds)
                ? $scanner->startScan(ScanType::SECTION, sectionIds: $this->sectionIds, progress: $progress)
                : $scanner->startScan(in_array($this->type, ScanType::STARTABLE, true) ? $this->type : ScanType::FULL, progress: $progress);

            $scanner->dispatchChecks($scan, $this->lockOwner);
        } catch (Throwable $e) {
            $scanner->releaseLock($this->lockOwner);

            throw $e;
        }
    }

    public function failed(?Throwable $e = null): void
    {
        app(Scanner::class)->releaseLock($this->lockOwner);
    }

    #[Override]
    protected function defaultDescription(): string
    {
        if (! empty($this->sectionIds)) {
            return t('Appleseed: Scanning selected sections for broken links', category: 'appleseed');
        }

        return match ($this->type) {
            ScanType::INCREMENTAL => t('Appleseed: Scanning changed entries for broken links', category: 'appleseed'),
            ScanType::RECHECK => t('Appleseed: Re-checking broken links', category: 'appleseed'),
            ScanType::DATABASE => t('Appleseed: Scanning entry content for broken links', category: 'appleseed'),
            default => t('Appleseed: Scanning for broken links', category: 'appleseed'),
        };
    }
}
