<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Jobs;

use CraftCms\Cms\Queue\Job;
use Illuminate\Bus\Batchable;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Services\Scanner;
use Override;

use function CraftCms\Cms\t;

/**
 * Checks one chunk of a scan's links (`scanBatchSize` of them). Dispatched as a `Bus::batch()` by
 * {@see Scanner::dispatchChecks()}, so several workers can share a scan and the batch knows when
 * the last chunk is done. (`CheckLinksBatchedJob` in Appleseed 5, which re-queued itself.)
 */
class CheckLinkChunk extends Job
{
    use Batchable;

    /** A chunk of slow, rate-limited links to one host can take a while. */
    public int $timeout = 3600;

    /** A chunk that fails leaves its links' previous results; the rest of the scan carries on. */
    public int $tries = 1;

    /**
     * @param  list<int>  $linkIds
     */
    public function __construct(
        public int $scanId,
        public array $linkIds,
    ) {
        parent::__construct();
    }

    public function handle(Scanner $scanner): void
    {
        // Cancelled (or abandoned and failed): skip the rest without touching the network
        if ($this->batch()?->cancelled() || ! $scanner->isScanRunning($this->scanId)) {
            return;
        }

        $scan = Scan::query()->find($this->scanId);

        if (! $scan instanceof Scan) {
            return;
        }

        $scanner->checkChunk($scan, $this->linkIds);
        $scanner->updateScanProgress($scan);
    }

    /**
     * Not Craft's `ShouldRun`, which deletes any job without a queue-manager row. The database
     * queue inserts a batch's jobs in bulk without the `JobQueued` event that creates those rows
     * (the plugin adds them from a payload hook instead), and a batch whose job was deleted would
     * never finish. Cancelling the scan cancels the batch.
     *
     * @return array<object>
     */
    #[Override]
    public function middleware(): array
    {
        return [];
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('Appleseed: Checking links for scan #{id}', ['id' => $this->scanId], category: 'appleseed');
    }
}
