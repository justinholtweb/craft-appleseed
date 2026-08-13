# Changelog

## 5.2.0 - 2026-08-13

### Added

- New "Default Status Filter" setting (`defaultStatusFilter`) controlling which status the dashboard results table is filtered by when you first open it — set it to Broken to land straight on the links that need attention. The filter dropdown still switches to any status from there.

### Fixed

- Scans no longer fail with `Data too long for column 'linkText'` when a link wraps a block of content. A link around a card with a heading and summary produced link text thousands of characters long, which overflowed the column and aborted the whole scan. Link text is now whitespace-collapsed and capped at 500 characters wherever it's discovered — spidered pages previously applied no limit at all.
- The results pagination no longer renders every page number, which pushed the pager off the side of the page and made it unusable once a scan turned up more than a few thousand links. It now shows the first and last pages, the two either side of the current one, and Prev/Next links, and wraps rather than overflowing.
- Pagination links now carry the active sort and direction, so paging no longer resets the sort order.
- Long link text is truncated in the results table instead of stretching the column; the full text is available on hover.
- The "Scan Batch Size" setting is now saved. It was rendered on the settings page but never read from the submitted form, so edits to it were silently discarded.
- Filtering the results table no longer resets the sort order.

## 5.1.1 - 2026-08-12

### Fixed

- Links to entries and assets in CKEditor and other rich text fields are no longer reported as broken. Rich text was read via `getRawContent()`, so element reference tags were never resolved and the link was recorded as the tag itself resolved against the site base URL (e.g. `https://example.com/{entry:123@1:url||https://example.com/page}`). Rich text is now read as parsed content, and any reference tag that still reaches link discovery is resolved (or skipped, if the referenced element is gone and the tag has no fallback URL).
- The spider now skips unparsed reference tags found in rendered pages rather than recording them as broken links.
- Existing link records containing unparsed reference tags are removed on update; the next scan records their real URLs.

### Changed

- PHPStan analysis raised from level 0 to level 5, with the type annotations and null checks needed to pass cleanly.

## 5.1.0 - 2026-07-15

### Added

- Scans now check links in batches using Craft's [batched jobs](https://craftcms.com/docs/5.x/extend/queue-jobs.html#batched-jobs). Discovery runs in the initial `ScanJob`, then link checking is handed off to a new `CheckLinksBatchedJob` that processes links in configurable batches, so no single queue job runs long enough to hit the queue's time limit on large sites. ([#1](https://github.com/justinholtweb/craft-appleseed/issues/1))
- New "Scan Batch Size" setting (`scanBatchSize`, default 50) controlling how many links each queue job checks before handing off to the next batch.

### Changed

- Scan progress tallies now update after each batch, so the dashboard progress bar advances throughout a queued scan rather than only at the end. Console scans (`appleseed/scan`) and entry-save scans continue to run synchronously in a single process.

## 5.0.3 - 2026-04-30

### Security

- Notification-failure log lines now mask recipient email addresses (e.g. `j***@example.com`) instead of writing them in plain text to Craft logs.

## 5.0.2 - 2026-04-15

### Added

- Changelog file with initial entries for 5.0.x releases.

## 5.0.0 - 2026-03-24

### Added

- Strict in_array(): Added true 3rd arg in LinkChecker.php:60, Scanner.php:179,199, Reporting.php:70. 
- Import inline FQCNs: \craft\helpers\UrlHelper, \craft\web\View, \yii\web\NotFoundHttpException, \yii\web\Response::FORMAT_HTML now properly imported in Reporting.php, DashboardController.php, scanController.php.
- Translation wrappers: Wrapped user-facing strings in Craft::t('appleseed', ...) — CP nav labels, permission labels/heading in Plugin.php, flash messages and AJAX response messages in DashboardController.php and SettingsController.php.
- Type declarations: LinkExtractor::_extractFromField() now accepts FieldInterface instead of mixed.
- String emptiness checks: Settings.php uses === '' instead of empty() for string vars.
- Unused imports removed: craft\models\Section, craft\helpers\Db, LinkSourceRecord, ScanRecord dropped from DashboardController / Reporting.

## 5.0.0 - 2026-03-24

### Added
- Initial release
- Hybrid link discovery (database scan + HTTP spider)
- Smart HTTP link checking with HEAD/GET fallback and retries
- CP dashboard with summary cards, filterable results table, and detail views
- Scheduled scans (daily, weekly, monthly)
- Entry-save scanning
- Email notifications when broken links exceed threshold
- CSV export
- Ignore patterns (regex-based)
- Console commands (`appleseed/scan`, `appleseed/check-url`)
- Badge count in CP nav
