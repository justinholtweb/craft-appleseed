<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Appleseed\Services\LinkChecker;
use JustinHoltWeb\Appleseed\Support\HostThrottle;
use JustinHoltWeb\Appleseed\Support\LinkStatus;

beforeEach(function () {
    $this->settings()->rateLimitPerSecond = 100;
    $this->settings()->maxRetries = 2;
});

it('falls back to GET when HEAD is refused', function () {
    Http::fake(fn (Request $request) => $request->method() === 'HEAD' ? Http::response('', 405) : Http::response('hello'));

    $result = app(LinkChecker::class)->checkUrl('https://ok.example.com/page');

    expect($result->status)->toBe(LinkStatus::WORKING)->and($result->statusCode)->toBe(200);
    Http::assertSentCount(2);
});

it('reports 404s as broken and 5xx as server errors', function () {
    Http::fake([
        'https://ok.example.com/missing' => Http::response('', 404),
        'https://ok.example.com/error' => Http::response('', 503),
    ]);

    $results = app(LinkChecker::class)->checkMany(['https://ok.example.com/missing', 'https://ok.example.com/error']);

    expect($results['https://ok.example.com/missing']->status)->toBe(LinkStatus::BROKEN)
        ->and($results['https://ok.example.com/error']->status)->toBe(LinkStatus::SERVER_ERROR);
});

it('follows redirects and records the chain', function () {
    Http::fake([
        'https://ok.example.com/old' => Http::response('', 301, ['Location' => 'https://ok.example.com/new']),
        'https://ok.example.com/new' => Http::response('here'),
    ]);

    $result = app(LinkChecker::class)->checkUrl('https://ok.example.com/old');

    expect($result->status)->toBe(LinkStatus::REDIRECT)
        ->and($result->redirectUrl)->toBe('https://ok.example.com/new')
        ->and($result->redirectChain)->toBe(['https://ok.example.com/new']);
});

it('stops at a redirect into a private address', function () {
    Http::fake([
        'https://ok.example.com/sneaky' => Http::response('', 302, ['Location' => 'http://metadata.internal/latest']),
        '*' => Http::response('secret'),
    ]);

    $result = app(LinkChecker::class)->checkUrl('https://ok.example.com/sneaky');

    expect($result->status)->toBe(LinkStatus::IGNORED)
        ->and($result->errorMessage)->toContain('private or internal');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'metadata.internal'));
});

it('never requests private addresses, other schemes or ignored URLs', function () {
    Http::fake();
    $this->settings()->ignorePatterns = '#^https://ok\.example\.com/skip#';

    $results = app(LinkChecker::class)->checkMany([
        'http://169.254.169.254/latest/meta-data',
        'http://intranet.internal/',
        'ftp://ok.example.com/file',
        'https://ok.example.com/skip/this',
    ]);

    foreach ($results as $result) {
        expect($result->status)->toBe(LinkStatus::IGNORED);
    }

    expect($results['http://169.254.169.254/latest/meta-data']->errorMessage)->toContain('private or internal');
    Http::assertNothingSent();
});

it('calls bot protection blocked, not broken', function () {
    Http::fake([
        'https://cf.example.com/*' => Http::response('Just a moment…', 403, ['cf-mitigated' => 'challenge']),
        'https://akamai.example.com/*' => Http::response('<a href="https://errors&period;edgesuite&period;net/18.1">Reference</a>', 403),
    ]);

    $results = app(LinkChecker::class)->checkMany(['https://cf.example.com/a', 'https://akamai.example.com/b']);

    expect($results['https://cf.example.com/a']->status)->toBe(LinkStatus::BLOCKED)
        ->and($results['https://cf.example.com/a']->errorMessage)->toContain('Cloudflare')
        ->and($results['https://akamai.example.com/b']->status)->toBe(LinkStatus::BLOCKED)
        ->and($results['https://akamai.example.com/b']->errorMessage)->toContain('Akamai');
});

it('retries failed connections with backoff, then classifies the failure', function () {
    $attempts = 0;
    Http::fake(function (Request $request) use (&$attempts) {
        $attempts++;

        return str_contains($request->url(), 'slow.')
            ? Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds')
            : Http::failedConnection('cURL error 6: Could not resolve host: nxdomain.example.com');
    });
    $started = HostThrottle::now();

    $results = app(LinkChecker::class)->checkMany(['https://slow.example.com/a', 'https://nxdomain.example.com/b']);

    expect($results['https://slow.example.com/a']->status)->toBe(LinkStatus::TIMEOUT)
        ->and($results['https://nxdomain.example.com/b']->status)->toBe(LinkStatus::DNS_ERROR);

    // HEAD, then two retries (after 1s and 2s), for each host.
    expect($attempts)->toBe(6);
    expect(HostThrottle::now() - $started)->toBeGreaterThanOrEqual(3.0);
});

it('checks several hosts at once, but never one host faster than the rate limit', function () {
    $this->settings()->rateLimitPerSecond = 1;
    $this->freezeTime();
    $sent = [];
    Http::fake(function (Request $request) use (&$sent) {
        $sent[] = [parse_url($request->url(), PHP_URL_HOST), (float) Carbon::now()->format('U.u')];

        return Http::response('ok');
    });

    app(LinkChecker::class)->checkMany(['https://a.example.com/1', 'https://a.example.com/2', 'https://b.example.com/1']);

    [$a1, $b1, $a2] = $sent;
    expect([$a1[0], $b1[0], $a2[0]])->toBe(['a.example.com', 'b.example.com', 'a.example.com'])
        // a and b went in the same round…
        ->and(abs($b1[1] - $a1[1]))->toBeLessThan(0.5)
        // …and a's second request waited its second.
        ->and($a2[1] - $a1[1])->toBeGreaterThanOrEqual(1.0);
});

it('keeps one cookie jar per link across its requests', function () {
    $cookies = [];
    Http::fake(function (Request $request) use (&$cookies) {
        $cookies[] = $request->header('Cookie')[0] ?? null;

        return $request->method() === 'HEAD'
            ? Http::response('', 403, ['Set-Cookie' => 'sso=1; Path=/'])
            : Http::response('welcome');
    });

    $result = app(LinkChecker::class)->checkUrl('https://sso.example.com/login');

    expect($result->status)->toBe(LinkStatus::WORKING)
        ->and($cookies)->toBe([null, 'sso=1']);
});

it('reports a non-standard status such as LinkedIn’s 999 as blocked', function () {
    $result = (new ReflectionMethod(LinkChecker::class, 'handle'))->invoke(app(LinkChecker::class), [
        'key' => 'x', 'url' => 'https://www.linkedin.com/in/someone', 'host' => 'www.linkedin.com', 'method' => 'GET',
        'try' => 0, 'notBefore' => 0.0, 'jar' => null, 'stats' => new ArrayObject(['httpCode' => 999]),
    ], new RuntimeException('Status code must be an integer value between 1xx and 5xx.'));

    expect($result->status)->toBe(LinkStatus::BLOCKED)->and($result->statusCode)->toBe(999);
});
