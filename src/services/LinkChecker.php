<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Services;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JustinHoltWeb\Appleseed\Data\CheckResult;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Support\Honeypot;
use JustinHoltWeb\Appleseed\Support\HostThrottle;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\LinkUrl;
use JustinHoltWeb\Appleseed\Support\UrlGuard;
use JustinHoltWeb\Appleseed\Support\UrlRefusedException;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Checks URLs with Laravel's HTTP client.
 *
 * - **Concurrency without hammering anyone.** {@see checkMany()} sends requests to several hosts at
 *   once with `Http::pool()`, at most one request per host in each round, and never faster than
 *   the per-host rate ({@see HostThrottle}, shared across queue workers).
 * - HEAD first; GET when a server answers HEAD with 400, 403 or 405.
 * - Retries a failed connection with exponential backoff (1s, 2s, 4s…), up to `maxRetries`.
 * - A fresh cookie jar per link, kept across its GET fallback and retries, for sign-on redirects
 *   that set a cookie and bounce back to the same URL.
 * - {@see UrlGuard} before every request and at every redirect hop (Guzzle's `on_redirect`).
 * - Black Hole's trap and URLs matching an ignore pattern are never requested.
 * - Bot protection (Cloudflare's challenge, Akamai's "Access Denied", LinkedIn's 999) is `blocked`,
 *   not broken.
 */
class LinkChecker
{
    /** How many hosts are requested at once. */
    public const int MAX_HOSTS_AT_ONCE = 8;

    /** Redirects followed before a link is reported broken. */
    public const int MAX_REDIRECTS = 10;

    private int $sequence = 0;

    public function __construct(
        private readonly Settings $settings,
        private readonly HostThrottle $throttle,
    ) {}

    public function checkUrl(string $url): CheckResult
    {
        return $this->checkMany([$url])[$url];
    }

    /**
     * Checks every URL, several hosts at a time.
     *
     * @param  list<string>  $urls
     * @return array<string, CheckResult> Keyed by URL.
     */
    public function checkMany(array $urls): array
    {
        $results = [];
        /** @var array<string, list<array<string, mixed>>> $pending Attempts waiting, by host. */
        $pending = [];

        foreach (array_unique($urls) as $url) {
            $refused = $this->precheck($url);

            if ($refused !== null) {
                $results[$url] = $refused;

                continue;
            }

            $pending[(string) LinkUrl::host($url)][] = $this->newAttempt($url);
        }

        while ($pending !== []) {
            $now = HostThrottle::now();
            $round = [];
            $soonest = null;

            foreach ($pending as $host => $queue) {
                if (count($round) >= self::MAX_HOSTS_AT_ONCE) {
                    break;
                }

                $attempt = $queue[0];
                $wait = $attempt['notBefore'] > $now
                    ? $attempt['notBefore'] - $now
                    : $this->throttle->attempt((string) $host, $this->settings->rateLimitPerSecond);

                if ($wait > 0) {
                    $soonest = $soonest === null ? $wait : min($soonest, $wait);

                    continue;
                }

                array_shift($pending[$host]);

                if ($pending[$host] === []) {
                    unset($pending[$host]);
                }

                $round[$attempt['key']] = $attempt;
            }

            if ($round === []) {
                Sleep::usleep((int) ceil(max(0.001, (float) $soonest) * 1_000_000));

                continue;
            }

            $outcomes = Http::pool(fn (Pool $pool) => array_map(
                fn (array $attempt) => $this->request($pool, $attempt),
                array_values($round),
            ));

            foreach ($round as $key => $attempt) {
                $next = $this->handle($attempt, $outcomes[$key] ?? null);

                if ($next instanceof CheckResult) {
                    $results[$attempt['url']] = $next;
                } else {
                    // Retried or falling back to GET: first in line for its host.
                    $pending[$attempt['host']] = [$next, ...($pending[$attempt['host']] ?? [])];
                }
            }
        }

        return $results;
    }

    /**
     * A result without a request, for URLs Appleseed won't fetch; null for the rest.
     */
    private function precheck(string $url): ?CheckResult
    {
        // Never request a honeypot trap: it bans the scanning server's address on any method,
        // HEAD included, and every check after that comes back as a 403
        if (Honeypot::isTrapUrl($url) || $this->settings->matchesIgnorePattern($url)) {
            return new CheckResult($url, LinkStatus::IGNORED);
        }

        // Never request an internal address an editor typed into content
        if (($refusal = UrlGuard::refusal($url)) !== null) {
            return new CheckResult($url, LinkStatus::IGNORED, errorMessage: $refusal);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function newAttempt(string $url): array
    {
        return [
            'key' => 'link'.(++$this->sequence),
            'url' => $url,
            'host' => (string) LinkUrl::host($url),
            'method' => 'HEAD',
            'try' => 0,
            'notBefore' => 0.0,
            // Sign-on redirects (iModules and the like) set a cookie and redirect back to the
            // same URL. A jar per link keeps one site's cookies from reaching another check.
            'jar' => new CookieJar,
            'stats' => new \ArrayObject,
        ];
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    private function request(Pool $pool, array $attempt): mixed
    {
        /** @var \ArrayObject<string, int|null> $stats */
        $stats = $attempt['stats'];

        return $pool->as($attempt['key'])
            ->timeout($this->settings->timeout)
            ->connectTimeout($this->settings->timeout)
            ->withUserAgent($this->settings->getUserAgentParsed())
            ->accept('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
            ->withOptions([
                'cookies' => $attempt['jar'],
                'verify' => true,
                'allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'track_redirects' => true,
                    // A public URL that redirects to an internal one is refused at the hop, not
                    // followed: the check before the request only saw the first address.
                    'on_redirect' => UrlGuard::onRedirect(...),
                ],
                // Guzzle refuses to build a response with a status outside 100-599 (LinkedIn
                // answers bots with 999), so keep the code the handler saw
                'on_stats' => function (TransferStats $transfer) use ($stats): void {
                    $code = $transfer->getHandlerStat('http_code');
                    $stats['httpCode'] = is_numeric($code) ? (int) $code : null;
                },
            ])
            ->send($attempt['method'], $attempt['url']);
    }

    /**
     * The result, or the attempt to make next.
     *
     * @param  array<string, mixed>  $attempt
     * @return CheckResult|array<string, mixed>
     */
    private function handle(array $attempt, mixed $outcome): CheckResult|array
    {
        $url = $attempt['url'];

        if ($outcome instanceof Response) {
            // Some servers don't support HEAD: try GET
            if ($attempt['method'] === 'HEAD' && in_array($outcome->status(), [400, 403, 405], true)) {
                return ['method' => 'GET'] + $attempt;
            }

            return $this->buildResult($url, $outcome);
        }

        $exception = $outcome instanceof Throwable ? $outcome : new ConnectionException('No response.');

        if (($refused = self::refusedIn($exception)) !== null) {
            // Refused at a redirect hop: there is nothing to retry.
            return new CheckResult($url, LinkStatus::IGNORED, errorMessage: $refused->getMessage());
        }

        $handlerCode = $attempt['stats']['httpCode'] ?? null;

        if (is_int($handlerCode) && $handlerCode >= 600) {
            // A non-standard status is a server turning the checker away, not a dead link
            return new CheckResult(
                $url,
                LinkStatus::BLOCKED,
                statusCode: $handlerCode,
                errorMessage: t('The server answered with non-standard status {code}, which sites such as LinkedIn use to turn away automated requests. Check this link in a browser.', ['code' => $handlerCode], category: 'appleseed'),
            );
        }

        if ($attempt['try'] < $this->settings->maxRetries) {
            // Exponential backoff: 1s, 2s, 4s…
            $try = $attempt['try'] + 1;

            return ['try' => $try, 'notBefore' => HostThrottle::now() + 2 ** ($try - 1)] + $attempt;
        }

        return self::errorResult($url, $exception);
    }

    private function buildResult(string $url, Response $response): CheckResult
    {
        $statusCode = $response->status();
        $history = $response->toPsrResponse()->getHeader('X-Guzzle-Redirect-History');
        $chain = $history !== [] ? array_values(array_map('strval', $history)) : null;
        $finalUrl = $chain !== null ? (string) end($chain) : null;

        if (($blockedBy = self::botProtection($response)) !== null) {
            return new CheckResult(
                $url,
                LinkStatus::BLOCKED,
                statusCode: $statusCode,
                redirectUrl: $finalUrl,
                redirectChain: $chain,
                errorMessage: t('{protection} challenged the request as a bot, so the link could not be checked. Check it in a browser.', ['protection' => $blockedBy], category: 'appleseed'),
            );
        }

        // Still a redirect after following them: the redirect limit was hit
        if ($statusCode >= 300 && $statusCode < 400 && $response->header('Location') !== '') {
            return new CheckResult(
                $url,
                LinkStatus::BROKEN,
                statusCode: $statusCode,
                redirectUrl: $response->header('Location'),
                redirectChain: $chain,
                errorMessage: t('Will not follow more than {max} redirects.', ['max' => self::MAX_REDIRECTS], category: 'appleseed'),
            );
        }

        $status = match (true) {
            $chain !== null => LinkStatus::REDIRECT,
            $statusCode >= 400 && $statusCode < 500 => LinkStatus::BROKEN,
            $statusCode >= 500 => LinkStatus::SERVER_ERROR,
            default => LinkStatus::WORKING,
        };

        return new CheckResult($url, $status, statusCode: $statusCode, redirectUrl: $finalUrl, redirectChain: $chain);
    }

    /**
     * Names the bot protection that answered instead of the site, or null for a real response.
     * A challenge only proves the checker isn't a browser; it says nothing about the link.
     */
    public static function botProtection(Response $response): ?string
    {
        $statusCode = $response->status();

        if ($statusCode !== 403 && $statusCode !== 503) {
            return null;
        }

        if (strtolower($response->header('cf-mitigated')) === 'challenge') {
            return 'Cloudflare';
        }

        // Akamai's "Access Denied" page links its reference number to errors.edgesuite.net. The
        // page entity-encodes its text, dots included.
        if ($statusCode === 403 && str_contains(html_entity_decode(substr($response->body(), 0, 4096), ENT_QUOTES | ENT_HTML5), 'errors.edgesuite.net')) {
            return 'Akamai';
        }

        return null;
    }

    /**
     * The result for a request that failed without a response, once retries are spent.
     */
    public static function errorResult(string $url, Throwable $exception): CheckResult
    {
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : t('Unknown error', category: 'appleseed');
        $lower = strtolower($message);

        if ($exception instanceof ConnectionException) {
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                return new CheckResult($url, LinkStatus::TIMEOUT, errorMessage: $message);
            }

            if (str_contains($lower, 'could not resolve') || str_contains($lower, 'name or service not known')) {
                return new CheckResult($url, LinkStatus::DNS_ERROR, errorMessage: $message);
            }
        }

        return new CheckResult($url, LinkStatus::BROKEN, errorMessage: $message);
    }

    private static function refusedIn(Throwable $exception): ?UrlRefusedException
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof UrlRefusedException) {
                return $e;
            }
        }

        return null;
    }
}
