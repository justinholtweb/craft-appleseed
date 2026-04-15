# Changelog

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
