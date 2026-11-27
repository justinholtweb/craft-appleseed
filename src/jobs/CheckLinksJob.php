<?php

namespace justinholtweb\appleseed\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;

/**
 * Re-checks individual links outside of a scan.
 */
class CheckLinksJob extends BaseJob
{
    /** @var int[] Link IDs to re-check */
    public array $linkIds = [];

    public function execute($queue): void
    {
        $scanner = Plugin::getInstance()->scanner;
        $total = count($this->linkIds);

        foreach (array_values($this->linkIds) as $i => $linkId) {
            $linkRecord = LinkRecord::findOne($linkId);
            if ($linkRecord && !$linkRecord->isIgnored) {
                $scanner->checkLink($linkRecord);
            }

            $this->setProgress($queue, ($i + 1) / max(1, $total), Craft::t('appleseed', 'Checked {num} of {total}', ['num' => $i + 1, 'total' => $total]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('appleseed', 'Appleseed: Re-checking {count, plural, =1{# link} other{# links}}', ['count' => count($this->linkIds)]);
    }
}
