<?php

declare(strict_types=1);

use CraftCms\Cms\Cp\Data\NavItem;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Plugin;

it('keeps every 5.3.0 setting, with the same defaults', function () {
    $settings = new Settings;

    expect($settings->checkExternalLinks)->toBeTrue()
        ->and($settings->timeout)->toBe(10)
        ->and($settings->maxRetries)->toBe(3)
        ->and($settings->rateLimitPerSecond)->toBe(1.0)
        ->and($settings->spiderEnabled)->toBeTrue()
        ->and($settings->maxPagesToSpider)->toBe(200)
        ->and($settings->scanBatchSize)->toBe(50)
        ->and($settings->scanFrequency)->toBe('manual')
        ->and($settings->scheduledScanType)->toBe('full')
        ->and($settings->scanOnEntrySave)->toBeFalse()
        ->and($settings->notificationThreshold)->toBe(1)
        ->and($settings->notifyOnlyNew)->toBeFalse()
        ->and($settings->userAgent)->toBe('Appleseed Link Checker (Craft CMS)')
        ->and($settings->defaultStatusFilter)->toBe('');

    foreach (['notificationEmails', 'ignorePatterns', 'emailLayoutTemplate', 'slackWebhookUrl', 'webhookUrl', 'webhookSecret', 'excludedFields', 'navLabel', 'navIcon', 'navIconSvg'] as $key) {
        expect(property_exists($settings, $key))->toBeTrue($key);
    }
});

it('validates ranges, choices and URLs', function () {
    $settings = new Settings;
    $settings->timeout = 61;
    $settings->rateLimitPerSecond = 0.05;
    $settings->scanFrequency = 'hourly';
    $settings->defaultStatusFilter = 'nonsense';
    $settings->webhookUrl = 'ftp://example.com/hook';

    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->keys())->toContain('timeout', 'rateLimitPerSecond', 'scanFrequency', 'defaultStatusFilter', 'webhookUrl');

    $settings = new Settings;
    $settings->webhookUrl = '$APPLESEED_WEBHOOK_URL';
    $settings->slackWebhookUrl = 'https://hooks.slack.com/services/T/B/x';
    $settings->defaultStatusFilter = 'broken';
    expect($settings->validate())->toBeTrue();
});

it('accepts one <svg> and real icons, and refuses the rest', function () {
    $settings = new Settings;
    $settings->navIconSvg = '<svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z"/></svg>';
    $settings->navIcon = 'link';
    expect($settings->validate())->toBeTrue();

    $settings->navIconSvg = '/etc/passwd';
    $settings->navIcon = 'not-a-real-icon-name';
    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->keys())->toContain('navIconSvg', 'navIcon');
});

it('rejects an invalid ignore pattern and matches valid ones', function () {
    $settings = new Settings;
    $settings->ignorePatterns = "#^https://example\\.com/skip#\n/[unclosed/";

    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->has('ignorePatterns'))->toBeTrue()
        ->and($settings->matchesIgnorePattern('https://example.com/skip/me'))->toBeTrue()
        ->and($settings->matchesIgnorePattern('https://example.com/keep'))->toBeFalse();
});

it('parses list settings and environment variables', function () {
    putenv('APPLESEED_TEST_EMAILS=a@example.com, b@example.com');
    $_SERVER['APPLESEED_TEST_EMAILS'] = $_ENV['APPLESEED_TEST_EMAILS'] = 'a@example.com, b@example.com';

    $settings = new Settings;
    $settings->notificationEmails = '$APPLESEED_TEST_EMAILS';
    $settings->excludedFields = "body,\n  cta ";
    $settings->webhookUrl = '$APPLESEED_UNSET_WEBHOOK';

    expect($settings->getNotificationEmailsArray())->toBe(['a@example.com', 'b@example.com'])
        ->and($settings->getExcludedFieldsArray())->toBe(['body', 'cta'])
        ->and($settings->getWebhookUrlParsed())->toBe('')
        ->and($settings->getUserAgentParsed())->toBe('Appleseed Link Checker (Craft CMS)');
});

it('draws the custom nav label and a sanitised SVG, and never breaks the nav', function () {
    $settings = new Settings;
    $settings->navLabel = 'Links';
    $settings->navIconSvg = '<svg viewBox="0 0 16 16" onload="alert(1)"><script>alert(1)</script><path d="M0 0h16v16H0z"/></svg>';

    $item = Plugin::getInstance()->customiseNavItem(new NavItem()->label('Appleseed')->icon('x'), $settings);

    expect($item->label)->toBe('Links')
        ->and($item->iconSvg)->toContain('<svg')
        ->and($item->iconSvg)->not->toContain('onload')
        ->and($item->iconSvg)->not->toContain('<script');

    $settings->navIconSvg = 'not svg';
    expect(Plugin::getInstance()->customiseNavItem(new NavItem()->label('Appleseed')->icon('x'), $settings)->icon)->toBe('x');
});
