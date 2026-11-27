<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Events;

use JustinHoltWeb\Appleseed\Data\CheckResult;
use JustinHoltWeb\Appleseed\Models\Link;

/**
 * Dispatched when a check finds a link newly broken: it wasn't broken before (or had never been
 * checked), and now its status is broken, server_error, timeout or dns_error. Not dispatched again
 * while it stays broken. New in Appleseed 6.
 *
 * `$scan` is the scan that found it, or null for a one-off re-check.
 */
class LinkBroke
{
    public function __construct(
        public Link $link,
        public CheckResult $result,
        public ?int $scanId = null,
    ) {}
}
