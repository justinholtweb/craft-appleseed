<?php

declare(strict_types=1);

use JustinHoltWeb\Appleseed\Support\UrlGuard;
use JustinHoltWeb\Appleseed\Support\UrlRefusedException;

it('allows public hosts and the site’s own', function () {
    expect(UrlGuard::refusal('https://example.com/page'))->toBeNull()
        ->and(UrlGuard::refusal('https://localhost/news'))->toBeNull();
});

it('refuses other schemes, and hosts that resolve to private or reserved addresses', function (string $url) {
    expect(UrlGuard::refusal($url))->not->toBeNull();
})->with([
    'ftp://example.com/file',
    'javascript:alert(1)',
    'http://127.0.0.1:6379/',
    'http://10.1.2.3/',
    'http://192.168.0.1/admin',
    'http://169.254.169.254/latest/meta-data',
    'http://100.64.0.1/',
    'http://[::1]/',
    'http://[fd00::1]/',
    'http://intranet.internal/',
]);

it('knows public from private addresses', function () {
    expect(UrlGuard::isPublic('93.184.216.34'))->toBeTrue()
        ->and(UrlGuard::isPublic('2606:2800:220:1:248:1893:25c8:1946'))->toBeTrue()
        ->and(UrlGuard::isPublic('172.16.0.1'))->toBeFalse()
        ->and(UrlGuard::isPublic('198.18.0.1'))->toBeFalse()
        ->and(UrlGuard::isPublic('::ffff:10.0.0.1'))->toBeFalse()
        ->and(UrlGuard::isPublic('fe80::1'))->toBeFalse();
});

it('throws at a redirect hop to a refused address', function () {
    expect(fn () => UrlGuard::onRedirect(null, null, 'http://169.254.169.254/'))->toThrow(UrlRefusedException::class);

    UrlGuard::onRedirect(null, null, 'https://example.com/next');
    expect(true)->toBeTrue();
});
