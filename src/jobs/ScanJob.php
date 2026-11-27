<?php

namespace justinholtweb\appleseed\jobs;

use Craft;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use justinholtweb\appleseed\helpers\ScanType;
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
    /** @var string One of {@see ScanType::STARTABLE}. Ignored when `sectionIds` is set. */
    public string $type = ScanType::FULL;

    /** @var int[]|null */
    public ?array $sectionIds = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $progressCallback = function(string $message, float $progress) use ($queue) {
            $this->setProgress($queue, $progress, $message);
        };

        if (!empty($this->sectionIds)) {
            $scan = $plugin->scanner->startScan(ScanType::SECTION, null, null, $this->sectionIds, $progressCallback);
        } else {
            $type = in_array($this->type, ScanType::STARTABLE, true) ? $this->type : ScanType::FULL;
            $scan = $plugin->scanner->startScan($type, null, null, null, $progressCallback);
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
            return Craft::t('appleseed', 'Appleseed: Scanning selected sections for broken links');
        }

        return match ($this->type) {
            ScanType::INCREMENTAL => Craft::t('appleseed', 'Appleseed: Scanning changed entries for broken links'),
            ScanType::RECHECK => Craft::t('appleseed', 'Appleseed: Re-checking broken links'),
            ScanType::DATABASE => Craft::t('appleseed', 'Appleseed: Scanning entry content for broken links'),
            default => Craft::t('appleseed', 'Appleseed: Scanning for broken links'),
        };
    }
}
