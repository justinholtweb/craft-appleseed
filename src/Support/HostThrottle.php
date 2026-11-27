<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Spaces requests to each host at most `rate` a second, across every process that checks links.
 *
 * Cache-backed rather than per-process, because a scan's chunks can run on several queue workers
 * at once and the limit is a promise to the host, not to one worker. It's a "next free slot" per
 * host (the GCRA idea) rather than Laravel's RateLimiter: RateLimiter counts hits in fixed windows,
 * so `1 per second` still lets two requests through a few milliseconds apart either side of a
 * window boundary. A slot is only taken when the caller is about to send, under a short lock.
 *
 * Time comes from Carbon and waiting goes through Laravel's Sleep, so tests can fake both.
 */
class HostThrottle
{
    private const string PREFIX = 'appleseed:throttle:';

    /**
     * Takes the host's next slot if it's free now and returns 0.0; otherwise returns how many
     * seconds until it is, without taking it.
     */
    public function attempt(string $host, float $ratePerSecond): float
    {
        $interval = 1 / max(0.01, $ratePerSecond);
        $key = self::PREFIX.strtolower($host);

        return (float) Cache::lock($key.':lock', 5)->block(5, function () use ($key, $interval): float {
            $now = self::now();
            $next = (float) Cache::get($key, 0.0);

            if ($next > $now) {
                return $next - $now;
            }

            Cache::put($key, $now + $interval, (int) ceil($interval) + 60);

            return 0.0;
        });
    }

    /**
     * Waits for, then takes, the host's next slot.
     */
    public function wait(string $host, float $ratePerSecond): void
    {
        while (($wait = $this->attempt($host, $ratePerSecond)) > 0) {
            Sleep::usleep((int) ceil($wait * 1_000_000));
        }
    }

    public static function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
