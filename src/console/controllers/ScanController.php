<?php

namespace justinholtweb\appleseed\console\controllers;

use Craft;
use craft\console\Controller;
use craft\web\View;
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

        $progressCallback = function (string $message, float $progress) {
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
