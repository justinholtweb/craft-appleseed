<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Appleseed\Http\Controllers\DashboardController;
use JustinHoltWeb\Appleseed\Http\Controllers\EmailPreviewController;
use JustinHoltWeb\Appleseed\Http\Controllers\ExportController;
use JustinHoltWeb\Appleseed\Http\Controllers\LinksController;
use JustinHoltWeb\Appleseed\Http\Controllers\ScansController;
use JustinHoltWeb\Appleseed\Http\Controllers\SettingsController;
use JustinHoltWeb\Appleseed\Plugin;

use function CraftCms\Cms\cp_url;

// The CP group already applies `auth`, `can:accessCp` and the cpTrigger prefix. Craft only hides
// the nav item from users without access; these routes check Appleseed's permissions themselves.
// The paths are Appleseed 5's, so bookmarks and links in old report emails keep working.

Route::middleware('can:'.Plugin::PERMISSION_VIEW)->group(function () {
    Route::get('appleseed', fn () => redirect(cp_url('appleseed/dashboard')))->name('appleseed');
    Route::get('appleseed/dashboard', DashboardController::class)->name('appleseed.dashboard');
    Route::get('appleseed/dashboard/detail/{linkId}', [LinksController::class, 'show'])->whereNumber('linkId')->name('appleseed.links.show');
    Route::get('appleseed/dashboard/scan-progress', [ScansController::class, 'progress'])->name('appleseed.scans.progress');
    Route::get('appleseed/dashboard/export-csv', ExportController::class)->name('appleseed.export');
    Route::get('appleseed/preview-email', EmailPreviewController::class)->name('appleseed.preview-email');

    Route::middleware('can:'.Plugin::PERMISSION_SCAN)->group(function () {
        Route::post('appleseed/dashboard/run-scan', [ScansController::class, 'store'])->name('appleseed.scans.run');
        Route::post('appleseed/dashboard/cancel-scan', [ScansController::class, 'cancel'])->name('appleseed.scans.cancel');
        Route::post('appleseed/dashboard/scan-entry', [ScansController::class, 'entry'])->name('appleseed.scans.entry');
        Route::post('appleseed/dashboard/ignore-link', [LinksController::class, 'ignore'])->name('appleseed.links.ignore');
        Route::post('appleseed/dashboard/unignore-link', [LinksController::class, 'unignore'])->name('appleseed.links.unignore');
        Route::post('appleseed/dashboard/rescan-link', [LinksController::class, 'rescan'])->name('appleseed.links.rescan');
    });
});

Route::get('appleseed/settings', SettingsController::class)
    ->middleware('can:'.Plugin::PERMISSION_SETTINGS)
    ->name('appleseed.settings');
