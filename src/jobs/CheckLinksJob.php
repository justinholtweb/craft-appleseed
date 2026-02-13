<?php

namespace justinholtweb\appleseed\jobs;

use craft\helpers\Db;
use craft\queue\BaseJob;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;

class CheckLinksJob extends BaseJob
{
    /** @var int[] Link IDs to re-check */
    public array $linkIds = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $total = count($this->linkIds);

        foreach ($this->linkIds as $i => $linkId) {
            $linkRecord = LinkRecord::findOne($linkId);
            if (!$linkRecord) {
                continue;
            }

            $result = $plugin->linkChecker->checkUrl($linkRecord->url);

            $linkRecord->statusCode = $result->statusCode;
            $linkRecord->status = $result->status;
            $linkRecord->redirectUrl = $result->redirectUrl;
            $linkRecord->redirectChain = $result->redirectChain ? json_encode($result->redirectChain) : null;
            $linkRecord->errorMessage = $result->errorMessage;
            $linkRecord->lastCheckedAt = Db::prepareDateForDb(new \DateTime());
            $linkRecord->save(false);

            $this->setProgress($queue, ($i + 1) / max(1, $total), "Checked {$linkId}");
        }
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->linkIds);
        return "Appleseed: Re-checking {$count} link(s)";
    }
}
