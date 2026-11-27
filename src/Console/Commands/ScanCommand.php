<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Services\ScanAlreadyRunning;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Throwable;

/**
 * Runs a scan here (or queues it with `--queue`) and prints the tallies.
 *
 * The Craft 5 commands still work through `php craft`: `appleseed/scan` (full),
 * `appleseed/scan/incremental` and `appleseed/scan/recheck-broken`.
 */
#[Signature('appleseed:scan
    {--type=full : full, database, incremental (entries changed since the last scan) or recheck (links already broken)}
    {--queue : Queue the scan instead of running it here}')]
#[Description('Scans the site for broken links.')]
#[Aliases(['appleseed/scan', 'appleseed/scan/index', 'appleseed/scan/incremental', 'appleseed/scan/recheck-broken'])]
class ScanCommand extends Command
{
    use CraftCommand;

    public function handle(Scanner $scanner): int
    {
        $type = $this->typeFromInvocation() ?? (string) $this->option('type');

        if (! in_array($type, ScanType::STARTABLE, true)) {
            $this->components->error(sprintf('Unknown scan type “%s”. Use one of: %s', $type, implode(', ', ScanType::STARTABLE)));

            return self::INVALID;
        }

        if ($this->option('queue')) {
            if (! $scanner->queueScan($type)) {
                $this->components->error('A scan is already running.');

                return self::FAILURE;
            }

            $this->components->info('Scan queued.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Starting: %s…', ScanType::label($type)));
        $started = microtime(true);

        try {
            $scan = $scanner->runScan($type, progress: function (string $message): void {
                if ($this->output->isVerbose()) {
                    $this->line("  <fg=gray>{$message}</>");
                }
            });
        } catch (ScanAlreadyRunning $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('Scan #%d %s in %.1fs', $scan->id, $scan->status, microtime(true) - $started));
        $this->table(['', ''], [
            ['Links found', $scan->totalLinksFound],
            ['Links checked', $scan->totalLinksChecked],
            ['Working', $scan->workingCount],
            ['Redirects', $scan->redirectCount],
            ['Broken', $scan->brokenCount],
            ['Newly broken', $scan->newBrokenCount],
        ]);

        if ($scan->brokenCount > 0) {
            $this->line('Broken links found. See them in the control panel, or with `php artisan appleseed:links:export --status=broken`.');
        }

        return self::SUCCESS;
    }

    /**
     * The scan type a Craft 5 alias stands for.
     */
    private function typeFromInvocation(): ?string
    {
        $name = (string) $this->input->getFirstArgument();

        return match (true) {
            str_ends_with($name, 'appleseed/scan/incremental') => ScanType::INCREMENTAL,
            str_ends_with($name, 'appleseed/scan/recheck-broken') => ScanType::RECHECK,
            default => null,
        };
    }
}
