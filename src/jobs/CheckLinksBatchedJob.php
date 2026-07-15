<?php

namespace justinholtweb\appleseed\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\ScanRecord;

/**
 * Checks the links discovered by a scan in batches.
 *
 * Each batch checks up to {@see $batchSize} links before Craft pushes a
 * continuation job for the next slice, keeping any single job execution short
 * enough to stay within the queue's time limit on large sites. The scan is
 * finalized (tallied and, if warranted, reported) once the final batch runs.
 *
 * Must be pushed with {@see \craft\helpers\Queue::push()} so the batch's delay
 * and TTR settings carry over to the continuation jobs.
 */
class CheckLinksBatchedJob extends BaseBatchedJob
{
    /** @var int The scan whose links should be checked. */
    public int $scanId;

    protected function loadData(): Batchable
    {
        return new QueryBatcher(
            Plugin::getInstance()->scanner->getScanLinkQuery($this->scanId)
        );
    }

    protected function processItem(mixed $item): void
    {
        $scan = $this->_getScan();
        if (!$scan) {
            return;
        }

        /** @var LinkRecord $item */
        Plugin::getInstance()->scanner->checkScanLink($item, $scan);
    }

    protected function afterBatch(): void
    {
        $scan = $this->_getScan();
        if ($scan) {
            Plugin::getInstance()->scanner->updateScanProgress($scan);
        }
    }

    protected function after(): void
    {
        $scan = $this->_getScan();
        if ($scan) {
            Plugin::getInstance()->scanner->finalizeScan($scan);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('appleseed', 'Appleseed: Checking links for broken URLs');
    }

    private function _getScan(): ?ScanRecord
    {
        return ScanRecord::findOne($this->scanId);
    }
}
