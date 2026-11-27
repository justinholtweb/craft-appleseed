<?php

namespace justinholtweb\appleseed\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\web\View;
use justinholtweb\appleseed\helpers\Presenter;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\models\Settings;
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
    public ?int $entryId = null;

    /**
     * @var int|null Site ID for single-entry scan
     */
    public ?int $siteId = null;

    /**
     * @var string|null Output file path for preview-email
     */
    public ?string $output = null;

    /**
     * @var string|null Override recipient for test-email
     */
    public ?string $to = null;

    /**
     * @var int How many days of scan history `prune` keeps.
     */
    public int $days = 90;

    /**
     * @var bool Queue the scan instead of running it here.
     */
    public bool $queue = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'entry') {
            $options[] = 'entryId';
            $options[] = 'siteId';
        }

        if ($actionID === 'preview-email') {
            $options[] = 'output';
        }

        if ($actionID === 'test-email') {
            $options[] = 'to';
        }

        if ($actionID === 'prune') {
            $options[] = 'days';
        }

        if (in_array($actionID, ['index', 'incremental', 'recheck-broken'], true)) {
            $options[] = 'queue';
        }

        return $options;
    }

    /**
     * Run a full broken link scan.
     *
     * Usage: craft appleseed/scan [--queue]
     */
    public function actionIndex(): int
    {
        return $this->_run(ScanType::FULL, 'Starting full Appleseed scan...');
    }

    /**
     * Scan only the entries changed since the last scan. The one to run from cron between full scans.
     *
     * Usage: craft appleseed/scan/incremental [--queue]
     */
    public function actionIncremental(): int
    {
        return $this->_run(ScanType::INCREMENTAL, 'Scanning entries changed since the last scan...');
    }

    /**
     * Check the links already known to be broken again, without reading any content.
     *
     * Usage: craft appleseed/scan/recheck-broken [--queue]
     */
    public function actionRecheckBroken(): int
    {
        return $this->_run(ScanType::RECHECK, 'Re-checking broken links...');
    }

    /**
     * Stop the running scan. Results already checked are kept.
     *
     * Usage: craft appleseed/scan/cancel
     */
    public function actionCancel(): int
    {
        $count = Plugin::getInstance()->scanner->cancelRunningScans();
        $this->stdout($count ? "Cancelled {$count} running scan(s).\n" : "No scan was running.\n");

        return ExitCode::OK;
    }

    /**
     * Show the link totals, the running scan and recent scans.
     *
     * Usage: craft appleseed/scan/status
     */
    public function actionStatus(): int
    {
        $reporting = Plugin::getInstance()->reporting;
        $summary = $reporting->getDashboardSummary();

        $this->stdout("Links\n", Console::BOLD);
        foreach ($summary as $label => $count) {
            $this->stdout(sprintf("  %-10s %d\n", $label, $count));
        }

        $running = $reporting->getRunningScan();
        if ($running) {
            $this->stdout(sprintf("\nRunning: #%d (%s), %d of %d links checked\n", $running->id, $running->type, $running->totalLinksChecked, $running->totalLinksFound), Console::FG_YELLOW);
        }

        $this->stdout("\nRecent scans\n", Console::BOLD);
        foreach ($reporting->getScans(10) as $scan) {
            $data = Presenter::scan($scan);
            $this->stdout(sprintf(
                "  #%-5d %-11s %-10s %5d links  %4d broken (%d new)  %s\n",
                $data['id'],
                $data['type'],
                $data['status'],
                $data['totalLinksFound'],
                $data['brokenCount'],
                $data['newBrokenCount'],
                $data['completedAt'] ?? $data['startedAt'] ?? '',
            ));
        }

        $hosts = $reporting->getWorstHosts(5);
        if ($hosts !== []) {
            $this->stdout("\nHosts with the most broken links\n", Console::BOLD);
            foreach ($hosts as $host) {
                $this->stdout(sprintf("  %-40s %d\n", $host['host'], $host['broken']));
            }
        }

        return ExitCode::OK;
    }

    /**
     * Delete scan history older than --days (default 90). Links and their results are kept.
     *
     * Usage: craft appleseed/scan/prune [--days=90]
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->scanner->pruneScans($this->days);
        $this->stdout("Deleted {$deleted} scan(s) older than {$this->days} days.\n");

        return ExitCode::OK;
    }

    private function _run(string $type, string $intro): int
    {
        $plugin = Plugin::getInstance();

        if ($this->queue) {
            if (!$plugin->scanner->queueScan($type)) {
                $this->stderr("A scan is already running.\n");
                return ExitCode::TEMPFAIL;
            }

            $this->stdout("Scan queued.\n");
            return ExitCode::OK;
        }

        $this->stdout("{$intro}\n");
        $startTime = microtime(true);

        $progressCallback = function(string $message, float $progress) {
            $this->stdout("\r  {$message}");
        };

        try {
            $scan = $plugin->scanner->runScan($type, progressCallback: $progressCallback);
        } catch (\Throwable $e) {
            $this->stderr("\nScan failed: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $elapsed = round(microtime(true) - $startTime, 1);

        $this->stdout("\n\nScan {$scan->status} in {$elapsed}s\n");
        $this->stdout("  Links found:    {$scan->totalLinksFound}\n");
        $this->stdout("  Links checked:  {$scan->totalLinksChecked}\n");
        $this->stdout("  Working:        {$scan->workingCount}\n");
        $this->stdout("  Redirects:      {$scan->redirectCount}\n");
        $this->stdout("  Broken:         {$scan->brokenCount}\n");
        $this->stdout("  Newly broken:   {$scan->newBrokenCount}\n");

        if ($scan->brokenCount > 0) {
            $this->stdout("\nBroken links found! View details in the control panel.\n");
        }

        return ExitCode::OK;
    }

    /**
     * Scan a single entry for broken links.
     *
     * Usage: craft appleseed/scan/entry --entry-id=123 [--site-id=1]
     */
    public function actionEntry(): int
    {
        if (!$this->entryId) {
            $this->stderr("Please provide an entry ID with --entry-id=<id>\n");
            return ExitCode::USAGE;
        }

        $this->stdout("Scanning entry #{$this->entryId}...\n");

        $plugin = Plugin::getInstance();

        $progressCallback = function(string $message, float $progress) {
            $this->stdout("\r  {$message}");
        };

        try {
            $scan = $plugin->scanner->runEntryScan($this->entryId, $this->siteId, $progressCallback);
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

    /**
     * Preview the scan report email HTML.
     *
     * Usage: craft appleseed/scan/preview-email [--output=/path/to/file.html]
     */
    public function actionPreviewEmail(): int
    {
        $plugin = Plugin::getInstance();
        $scan = $plugin->reporting->getLastCompletedScan();

        if (!$scan) {
            $this->stderr("No completed scan found.\n");
            return ExitCode::DATAERR;
        }

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $plugin->reporting->renderScanReportEmail($scan);

        if ($this->output) {
            file_put_contents($this->output, $html);
            $this->stdout("Email HTML written to {$this->output}\n");
        } else {
            $this->stdout($html);
        }

        return ExitCode::OK;
    }

    /**
     * Send a test scan report email.
     *
     * Usage: craft appleseed/scan/test-email [--to=user@example.com]
     */
    public function actionTestEmail(): int
    {
        $plugin = Plugin::getInstance();
        $scan = $plugin->reporting->getLastCompletedScan();

        if (!$scan) {
            $this->stderr("No completed scan found.\n");
            return ExitCode::DATAERR;
        }

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = $plugin->reporting->renderScanReportEmail($scan);

        if ($this->to) {
            $recipients = array_map('trim', explode(',', $this->to));
        } else {
            /** @var Settings $settings */
            $settings = $plugin->getSettings();
            $recipients = $settings->getNotificationEmailsArray();
        }

        if (empty($recipients)) {
            $this->stderr("No recipients. Use --to=email@example.com or configure notification emails in settings.\n");
            return ExitCode::DATAERR;
        }

        foreach ($recipients as $email) {
            try {
                Craft::$app->getMailer()
                    ->compose()
                    ->setTo($email)
                    ->setSubject("[TEST] Appleseed: {$scan->brokenCount} broken link(s) found")
                    ->setHtmlBody($html)
                    ->send();
                $this->stdout("Sent test email to {$email}\n");
            } catch (\Throwable $e) {
                $this->stderr("Failed to send to {$email}: {$e->getMessage()}\n");
            }
        }

        return ExitCode::OK;
    }
}
