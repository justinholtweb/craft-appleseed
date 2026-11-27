<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Appleseed\Database\Migrations\Install;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Plugin;

it('keeps 5.3 rows when the install migration meets existing tables', function () {
    // A link as Appleseed 5.3 wrote it: UTC dates, JSON redirect chain, its own uid.
    DB::table('appleseed_links')->insert([
        'url' => 'https://example.com/old',
        'urlHash' => hash('sha256', 'https://example.com/old'),
        'host' => 'example.com',
        'statusCode' => 301,
        'status' => 'redirect',
        'redirectUrl' => 'https://example.com/new',
        'redirectChain' => json_encode(['https://example.com/new']),
        'lastCheckedAt' => '2026-11-01 12:00:00',
        'isIgnored' => false,
        'dateCreated' => '2026-10-01 12:00:00',
        'dateUpdated' => '2026-11-01 12:00:00',
        'uid' => 'b3f1c1d2-0000-4000-8000-000000000001',
    ]);

    // Installing Appleseed already loaded the migration (Craft requires it outside the autoloader).
    if (! class_exists(Install::class, false)) {
        require dirname(__DIR__, 2).'/database/migrations/Install.php';
    }

    new Install()->up();

    $link = Link::query()->where('url', 'https://example.com/old')->sole();

    expect($link->redirectChain)->toBe(['https://example.com/new'])
        ->and($link->lastCheckedAt?->utc()->format('Y-m-d H:i'))->toBe('2026-11-01 12:00')
        ->and($link->uid)->toBe('b3f1c1d2-0000-4000-8000-000000000001');
});

it('upgrades from the last 5.x schema, with the 5.x permission keys', function () {
    $plugin = Plugin::getInstance();

    expect($plugin->schemaVersion)->toBe('1.2.0')
        ->and($plugin->minVersionRequired)->toBe('5.3.0')
        ->and(Plugin::PERMISSION_VIEW)->toBe('appleseed-viewDashboard')
        ->and(Plugin::PERMISSION_SCAN)->toBe('appleseed-runScans')
        ->and(Plugin::PERMISSION_SETTINGS)->toBe('appleseed-manageSettings');

    // Craft 5 stored permission names lowercased; Craft 6 matches them case-insensitively.
    $user = $this->userWith(['appleseed-viewdashboard'], 'lowercase');
    expect($user->can(Plugin::PERMISSION_VIEW))->toBeTrue()
        ->and($user->can(Plugin::PERMISSION_SCAN))->toBeFalse();
});
