<?php

namespace justinholtweb\appleseed\jobs;

use craft\queue\BaseJob;
use justinholtweb\appleseed\Plugin;

class ScanJob extends BaseJob
{
    public string $type = 'full';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        $progressCallback = function (string $message, float $progress) use ($queue) {
            $this->setProgress($queue, $progress, $message);
        };

        if ($this->type === 'database') {
            $plugin->scanner->runDatabaseScan($progressCallback);
        } else {
            $plugin->scanner->runFullScan($progressCallback);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Appleseed: Scanning for broken links';
    }
}
