<?php

declare(strict_types=1);

use JustinHoltWeb\Appleseed\Services\LinkExtractor;
use JustinHoltWeb\Appleseed\Services\Spider;
use JustinHoltWeb\Appleseed\Support\Honeypot;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\LinkText;
use JustinHoltWeb\Appleseed\Support\LinkUrl;
use JustinHoltWeb\Appleseed\Support\ScanType;

it('knows which statuses are broken', function () {
    expect(LinkStatus::BROKEN_STATUSES)->toBe(['broken', 'dns_error', 'timeout', 'server_error'])
        ->and(LinkStatus::isBroken('timeout'))->toBeTrue()
        ->and(LinkStatus::isBroken('blocked'))->toBeFalse()
        ->and(LinkStatus::isBroken('redirect'))->toBeFalse()
        ->and(LinkStatus::normalizeFilter('nonsense'))->toBeNull()
        ->and(LinkStatus::normalizeFilter('ignored'))->toBe('ignored')
        ->and(array_column(LinkStatus::filterOptions(), 'value'))->toBe(['', 'broken', 'redirect', 'working', 'server_error', 'timeout', 'dns_error', 'blocked', 'unknown', 'ignored'])
        ->and(ScanType::STARTABLE)->toBe(['full', 'database', 'incremental', 'recheck']);
});

it('collapses and caps link text', function () {
    expect(LinkText::normalize("  Read\n\t more\u{00A0}here  "))->toBe('Read more here')
        ->and(LinkText::normalize('   '))->toBeNull()
        ->and(LinkText::normalize(null))->toBeNull()
        ->and(mb_strlen((string) LinkText::normalize(str_repeat('a', 900))))->toBe(500)
        ->and(LinkText::normalize(str_repeat('a', 900)))->toEndWith('…');
});

it('strips credentials, finds hosts and hashes URLs', function () {
    expect(LinkUrl::stripCredentials('https://user:pa:ss@example.com/x'))->toBe('https://example.com/x')
        ->and(LinkUrl::host('HTTPS://Example.COM/x'))->toBe('example.com')
        ->and(LinkUrl::host('/relative'))->toBeNull()
        ->and(LinkUrl::hash('https://example.com/'))->toBe(hash('sha256', 'https://example.com/'))
        ->and(LinkUrl::isExternal('https://localhost/page'))->toBeFalse()
        ->and(LinkUrl::isExternal('https://example.com/page'))->toBeTrue();
});

it('normalises links found in content', function () {
    $extractor = app(LinkExtractor::class);

    expect($extractor->normalize('HTTPS://Example.COM/Path?q=1#frag', 1))->toBe('https://example.com/Path?q=1')
        ->and($extractor->normalize('//cdn.example.com/a.js', 1))->toBe('https://cdn.example.com/a.js')
        ->and($extractor->normalize('/about', 1))->toBe('https://localhost/about')
        ->and($extractor->normalize('mailto:a@example.com', 1))->toBeNull()
        ->and($extractor->normalize('tel:123', 1))->toBeNull()
        ->and($extractor->normalize('skype:someone', 1))->toBeNull()
        ->and($extractor->normalize('{entry:999999@1:url}', 1))->toBeNull()
        ->and($extractor->normalize('{entry:999999@1:url||https://example.com/fallback}', 1))->toBe('https://example.com/fallback');
});

it('resolves URLs on crawled pages', function () {
    expect(Spider::resolve('/a#b', 'https://example.com/dir/page'))->toBe('https://example.com/a')
        ->and(Spider::resolve('next', 'https://example.com/dir/page'))->toBe('https://example.com/dir/next')
        ->and(Spider::resolve('https://u:p@other.com/x', 'https://example.com/'))->toBe('https://other.com/x')
        ->and(Spider::resolve('{entry:1@1:url}', 'https://example.com/'))->toBeNull()
        ->and(Spider::resolve('javascript:void(0)', 'https://example.com/'))->toBeNull();
});

it('reads anchors and images, with nofollow', function () {
    $links = LinkExtractor::linksInHtml('<a href="/x" rel="nofollow noopener"> X  </a><img src="/i.png" alt="Pic">');

    expect($links)->toBe([
        ['url' => '/x', 'linkText' => 'X', 'nofollow' => true],
        ['url' => '/i.png', 'linkText' => 'Pic', 'nofollow' => false],
    ]);
});

it('has no honeypot to avoid without Black Hole', function () {
    expect(Honeypot::isTrapUrl('https://localhost/blackhole'))->toBeFalse();
});
