<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Services\Scanner;

#[Signature('appleseed:scan:cancel')]
#[Description('Stops the running scan. Results already checked are kept.')]
#[Aliases(['appleseed/scan/cancel'])]
class ScanCancelCommand extends Command
{
    use CraftCommand;

    public function handle(Scanner $scanner): int
    {
        $count = $scanner->cancelRunningScans();
        $this->line($count > 0 ? "Cancelled {$count} running scan(s)." : 'No scan was running.');

        return self::SUCCESS;
    }
}
