<?php

namespace justinholtweb\appleseed\console\controllers;

use craft\console\Controller;
use justinholtweb\appleseed\Plugin;
use yii\console\ExitCode;

/**
 * Scan for broken links.
 */
class ScanController extends Controller
{
    /**
     * @var int|null Entry ID for single-entry scan
     */
    public ?int $id = null;

    /**
     * @var int|null Site ID for single-entry scan
     */
    public ?int $siteId = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'entry') {
            $options[] = 'id';
            $options[] = 'siteId';
        }

        return $options;
    }

    /**
     * Run a full broken link scan.
     *
     * Usage: craft appleseed/scan
     */
    public function actionIndex(): int
    {
        $this->stdout("Starting full Appleseed scan...\n");
        $startTime = microtime(true);

        $plugin = Plugin::getInstance();

        $progressCallback = function (string $message, float $progress) {
            $this->stdout("\r  {$message}");
        };

        try {
            $scan = $plugin->scanner->runFullScan($progressCallback);
        } catch (\Throwable $e) {
            $this->stderr("\nScan failed: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $elapsed = round(microtime(true) - $startTime, 1);

        $this->stdout("\n\nScan completed in {$elapsed}s\n");
        $this->stdout("  Links found:    {$scan->totalLinksFound}\n");
        $this->stdout("  Links checked:  {$scan->totalLinksChecked}\n");
        $this->stdout("  Working:        {$scan->workingCount}\n");
        $this->stdout("  Redirects:      {$scan->redirectCount}\n");
        $this->stdout("  Broken:         {$scan->brokenCount}\n");

        if ($scan->brokenCount > 0) {
            $this->stdout("\nBroken links found! View details in the control panel.\n");
        }

        return ExitCode::OK;
    }

    /**
     * Scan a single entry for broken links.
     *
     * Usage: craft appleseed/scan/entry --id=123 [--site-id=1]
     */
    public function actionEntry(): int
    {
        if (!$this->id) {
            $this->stderr("Please provide an entry ID with --id=<id>\n");
            return ExitCode::USAGE;
        }

        $this->stdout("Scanning entry #{$this->id}...\n");

        $plugin = Plugin::getInstance();

        $progressCallback = function (string $message, float $progress) {
            $this->stdout("\r  {$message}");
        };

        try {
            $scan = $plugin->scanner->runEntryScan($this->id, $this->siteId, $progressCallback);
        } catch (\Throwable $e) {
            $this->stderr("\nScan failed: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n\nEntry scan completed\n");
        $this->stdout("  Links found:    {$scan->totalLinksFound}\n");
        $this->stdout("  Links checked:  {$scan->totalLinksChecked}\n");
        $this->stdout("  Broken:         {$scan->brokenCount}\n");

        return ExitCode::OK;
    }
}
