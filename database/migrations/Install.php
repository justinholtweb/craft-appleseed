<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Appleseed\Database\Tables;

/**
 * Appleseed's three tables, exactly as Appleseed 5.3.0 left them (schema 1.2.0).
 *
 * A site upgrading from Craft 5 already has all three (`$minVersionRequired` is 5.3.0, so its
 * migrations have run), so each table is skipped when it exists and its links, sources and scan
 * history carry over untouched.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(Tables::SCANS)) {
            Schema::create(Tables::SCANS, function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('status', 20)->default('pending');
                $table->string('type', 20)->default('full');
                $table->integer('totalLinksFound')->default(0);
                $table->integer('totalLinksChecked')->default(0);
                $table->integer('brokenCount')->default(0);
                $table->integer('newBrokenCount')->default(0);
                $table->integer('redirectCount')->default(0);
                $table->integer('workingCount')->default(0);
                $table->dateTime('startedAt')->nullable();
                $table->dateTime('completedAt')->nullable();
                $table->integer('entryId')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');
            });
        }

        if (! Schema::hasTable(Tables::LINKS)) {
            Schema::create(Tables::LINKS, function (Blueprint $table) {
                $table->integer('id', true);
                $table->text('url');
                $table->char('urlHash', 64);
                $table->string('host', 255)->nullable();
                $table->smallInteger('statusCode')->nullable();
                $table->string('status', 20)->default('unknown');
                $table->text('redirectUrl')->nullable();
                $table->text('redirectChain')->nullable();
                $table->text('errorMessage')->nullable();
                $table->dateTime('lastCheckedAt')->nullable();
                $table->dateTime('firstBrokenAt')->nullable();
                $table->integer('lastScanId')->nullable();
                $table->boolean('isIgnored')->default(false);
                $table->integer('ignoredById')->nullable();
                $table->dateTime('dateIgnored')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                $table->unique('urlHash');
                $table->index('status');
                $table->index('isIgnored');
                $table->index('lastScanId');
                $table->index('host');

                $table->foreign('ignoredById')->references('id')->on(Table::USERS)->nullOnDelete();
                $table->foreign('lastScanId')->references('id')->on(Tables::SCANS)->nullOnDelete();
            });
        }

        if (! Schema::hasTable(Tables::LINK_SOURCES)) {
            Schema::create(Tables::LINK_SOURCES, function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('linkId');
                $table->integer('entryId')->nullable();
                $table->integer('siteId')->nullable();
                $table->string('fieldHandle', 255)->nullable();
                $table->string('linkText', 500)->nullable();
                $table->string('sourceType', 20);
                $table->text('sourceUrl')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                $table->index('linkId');
                $table->index('entryId');
                $table->index('siteId');

                $table->foreign('linkId')->references('id')->on(Tables::LINKS)->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::LINK_SOURCES);
        Schema::dropIfExists(Tables::LINKS);
        Schema::dropIfExists(Tables::SCANS);
    }
}
