# Changelog

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
