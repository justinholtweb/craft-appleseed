<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use RuntimeException;

/**
 * A redirect to an address {@see UrlGuard} won't let Appleseed request. Not a failure to retry.
 */
final class UrlRefusedException extends RuntimeException {}
