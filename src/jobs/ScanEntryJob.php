<?php

namespace justinholtweb\appleseed\jobs;

use craft\queue\BaseJob;
use justinholtweb\appleseed\Plugin;

class ScanEntryJob extends BaseJob
{
    public int $entryId;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        $progressCallback = function (string $message, float $progress) use ($queue) {
            $this->setProgress($queue, $progress, $message);
        };

        $plugin->scanner->runEntryScan($this->entryId, $this->siteId, $progressCallback);
    }

    protected function defaultDescription(): ?string
    {
        return "Appleseed: Scanning entry #{$this->entryId} for broken links";
    }
}
