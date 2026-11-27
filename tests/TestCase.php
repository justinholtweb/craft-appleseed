<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Tests;

use CraftCms\Cms\Database\LaravelMigrations;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JustinHoltWeb\Appleseed\Support\UrlGuard;
use JustinHoltWeb\Appleseed\Tests\Support\CreatesContent;
use Override;

abstract class TestCase extends PluginTestCase
{
    use CreatesContent;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Solo allows one user account, and the permission tests need a second.
        Edition::set(Edition::Team);

        // No real requests, no real DNS, no real waiting: backoff and the per-host rate limit
        // advance Carbon's clock instead.
        Http::preventStrayRequests();
        UrlGuard::resolveUsing(fn (string $host) => str_ends_with($host, '.internal') ? ['10.0.0.5'] : ['93.184.216.34']);
        Sleep::fake(syncWithCarbon: true);

        // Craft creates `job_batches` on install and upgrade; the test database is built without
        // it, and the batch repository's own `sqlite` connection would be a second in-memory
        // database.
        config(['queue.batching.database' => config('database.default')]);
        app()->forgetInstance(BatchRepository::class);
        app()->forgetInstance(DatabaseBatchRepository::class);
        app(LaravelMigrations::class)->ensureQueueTables();
    }

    #[Override]
    protected function tearDown(): void
    {
        UrlGuard::resolveUsing(null);

        parent::tearDown();
    }

    /**
     * SQLite only enforces foreign keys when asked, and ignores the pragma inside a transaction,
     * so it's set before each test's transaction. `appleseed_link_sources.linkId` cascades.
     */
    #[Override]
    public function beginDatabaseTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        parent::beginDatabaseTransaction();
    }
}
