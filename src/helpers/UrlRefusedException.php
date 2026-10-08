<?php

namespace justinholtweb\appleseed\helpers;

use RuntimeException;

/**
 * A redirect to an address {@see UrlGuard} won't let Appleseed request. Not a failure to retry.
 */
final class UrlRefusedException extends RuntimeException
{
}
