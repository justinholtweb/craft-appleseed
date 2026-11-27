<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use RuntimeException;

/**
 * Another site scan holds the scan lock.
 */
final class ScanAlreadyRunning extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A scan is already running or queued.');
    }
}
