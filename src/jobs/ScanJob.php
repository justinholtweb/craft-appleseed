<?php

namespace justinholtweb\appleseed\jobs;

use craft\helpers\Queue;
use craft\queue\BaseJob;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;

/**
 * Discovers the links for a scan, then hands checking off to a batched job.
 *
 * Discovery (extracting and persisting links) runs here; the potentially
 * long-running, rate-limited checking of each link is delegated to
 * {@see CheckLinksBatchedJob} so it can be spread across multiple queue jobs
 * without hitting the queue's time limit on large sites.
 */
class ScanJob extends BaseJob
{
    public string $type = 'full';

    /** @var int[]|null */
    public ?array $sectionIds = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $progressCallback = function (string $message, float $progress) use ($queue) {
            $this->setProgress($queue, $progress, $message);
        };

        if (!empty($this->sectionIds)) {
            $scan = $plugin->scanner->startScan('section', null, null, $this->sectionIds, $progressCallback);
        } elseif ($this->type === 'database') {
            $scan = $plugin->scanner->startScan('database', null, null, null, $progressCallback);
        } else {
            $scan = $plugin->scanner->startScan('full', null, null, null, $progressCallback);
        }

        // Nothing to check — finalize immediately rather than queue an empty batch.
        if ($scan->totalLinksFound === 0) {
            $plugin->scanner->finalizeScan($scan);
            return;
        }

        Queue::push(new CheckLinksBatchedJob([
            'scanId' => $scan->id,
            'batchSize' => max(1, $settings->scanBatchSize),
        ]));
    }

    protected function defaultDescription(): ?string
    {
        if (!empty($this->sectionIds)) {
            return 'Appleseed: Scanning selected sections for broken links';
        }

        return 'Appleseed: Scanning for broken links';
    }
}
