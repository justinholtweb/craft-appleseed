<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use JustinHoltWeb\Appleseed\Mail\ScanReportMail;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\Reporting;
use Throwable;

/**
 * The report email for the last completed scan: written out (`--output`, or stdout), or sent as a
 * test (`--send`, to `--to` or the notification addresses).
 */
#[Signature('appleseed:scan:email
    {--send : Send it as a test, to --to or the notification emails}
    {--to= : Comma-separated recipients for --send}
    {--output= : Write the HTML here instead of to stdout}')]
#[Description('Previews or test-sends the scan report email for the last completed scan.')]
#[Aliases(['appleseed/scan/preview-email', 'appleseed/scan/test-email'])]
class ScanEmailCommand extends Command
{
    use CraftCommand;

    public function handle(Reporting $reporting, Settings $settings): int
    {
        $scan = $reporting->getLastCompletedScan();

        if ($scan === null) {
            $this->components->error('No completed scan found.');

            return self::INVALID;
        }

        $html = $reporting->renderScanReportEmail($scan);
        $send = $this->option('send') || str_ends_with((string) $this->input->getFirstArgument(), 'test-email');

        if (! $send) {
            if (($file = $this->option('output')) !== null) {
                file_put_contents((string) $file, $html);
                $this->line("Email HTML written to {$file}");
            } else {
                $this->output->write($html);
            }

            return self::SUCCESS;
        }

        $recipients = $this->option('to') !== null
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('to')))))
            : $settings->getNotificationEmailsArray();

        if ($recipients === []) {
            $this->components->error('No recipients. Use --to=email@example.com or set notification emails.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($recipients as $email) {
            try {
                // One mailable per recipient: `to()` appends.
                Mail::to($email)->sendNow(new ScanReportMail('[TEST] '.$reporting->reportSubject($scan), $html));
                $this->line("Sent test email to {$email}");
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Failed to send to {$email}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
