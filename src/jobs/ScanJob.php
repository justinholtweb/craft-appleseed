<?php

namespace justinholtweb\appleseed\jobs;

use craft\queue\BaseJob;
use justinholtweb\appleseed\Plugin;

class ScanJob extends BaseJob
{
    public string $type = 'full';

    /** @var int[]|null */
    public ?array $sectionIds = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        $progressCallback = function (string $message, float $progress) use ($queue) {
            $this->setProgress($queue, $progress, $message);
        };

        if (!empty($this->sectionIds)) {
            $plugin->scanner->runSectionScan($this->sectionIds, $progressCallback);
        } elseif ($this->type === 'database') {
            $plugin->scanner->runDatabaseScan($progressCallback);
        } else {
            $plugin->scanner->runFullScan($progressCallback);
        }
    }

    protected function defaultDescription(): ?string
    {
        if (!empty($this->sectionIds)) {
            return 'Appleseed: Scanning selected sections for broken links';
        }

        return 'Appleseed: Scanning for broken links';
    }
}
