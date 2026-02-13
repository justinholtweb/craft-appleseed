# Appleseed — AI Agent Guide

Broken link checker plugin for Craft CMS 5. Discovers links from entry fields and rendered pages, checks them via HTTP, and reports results in the control panel.

## Quick Orientation

```
src/
├── Plugin.php              # Entry point: service registration, events, nav, scheduling
├── models/
│   ├── Settings.php        # 12 configurable properties with validation
│   └── ScanResult.php      # Read-only DTO (url, status, statusCode, redirectUrl, etc.)
├── records/                # ActiveRecord wrappers for 3 database tables
├── migrations/Install.php  # Schema: appleseed_scans, appleseed_links, appleseed_link_sources
├── services/
│   ├── Scanner.php         # Orchestrator: discovery → checking → reporting
│   ├── LinkExtractor.php   # Extracts URLs from entry fields (CKEditor, Link, URL, Matrix, Table)
│   ├── Spider.php          # BFS crawl of rendered pages
│   ├── LinkChecker.php     # HEAD/GET with retries, rate limiting, redirect tracking
│   └── Reporting.php       # Dashboard queries, CSV export, email notifications
├── jobs/                   # Queue jobs: ScanJob, ScanEntryJob, CheckLinksJob
├── controllers/            # CP web controllers: Dashboard (AJAX + views), Settings
├── console/controllers/    # CLI: craft appleseed/scan, craft appleseed/check-url
├── templates/              # Twig: dashboard, settings, email
└── assets/                 # AssetBundle + CSS/JS for CP dashboard
```

## Architecture

### Service Dependency Graph

```
Scanner (orchestrator)
├── LinkExtractor  → queries Entry fields, parses HTML with DOMDocument
├── Spider         → BFS crawl via Guzzle, extracts <a href> and <img src>
├── LinkChecker    → HEAD-first with GET fallback, exponential backoff, per-domain rate limit
└── Reporting      → dashboard queries, CSV generation, email via Craft mailer
```

Services are registered as Yii2 components in `Plugin::config()` and accessed via `Plugin::getInstance()->serviceName`.

### Scan Workflow

1. **Create** `ScanRecord` (status=running)
2. **Discover** links via LinkExtractor (database fields) and/or Spider (HTTP crawl)
3. **Upsert** into `appleseed_links` (deduped by SHA-256 hash) + `appleseed_link_sources`
4. **Check** each URL via LinkChecker → update link records
5. **Finalize** scan stats, send email notification if threshold met

### Database Tables

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `appleseed_scans` | Scan run metadata | status, type, brokenCount, startedAt/completedAt |
| `appleseed_links` | Unique URLs + check results | url, urlHash (unique), status, statusCode, redirectUrl |
| `appleseed_link_sources` | Where each URL was found | linkId (FK CASCADE), entryId, fieldHandle, sourceType |

URLs are deduped by `urlHash = SHA-256(url)`. One link can have many sources (entries or spidered pages).

### Link Status Values

`working` · `broken` · `redirect` · `server_error` · `timeout` · `dns_error` · `ignored` · `unknown`

## Key Conventions

- **Namespace**: `justinholtweb\appleseed`
- **PHP 8.2+**: Uses readonly properties, named arguments, match expressions, first-class callables
- **Craft 5 patterns**: ActiveRecord, Component services, BaseJob queue jobs, Model validation, `Event::on()` listeners
- **Private methods**: Prefixed with underscore (`_runScan`, `_extractFromField`)
- **Progress callbacks**: Services accept `?callable $progressCallback` — signature is `(string $message, float $progress)`
- **HTTP client**: Always via `Craft::createGuzzleClient()` with `http_errors => false` and `track_redirects => true`
- **Templates**: Extend `_layouts/cp`, use Craft's `forms.*Field()` macros
- **JS**: Uses `Craft.sendActionRequest()` for AJAX, `Craft.cp.displayNotice()`/`displayError()` for toasts

## Settings (src/models/Settings.php)

| Property | Type | Default | Purpose |
|----------|------|---------|---------|
| `checkExternalLinks` | bool | true | Whether to check links to other domains |
| `timeout` | int | 10 | HTTP timeout in seconds (1–60) |
| `maxRetries` | int | 3 | Retry attempts with exponential backoff (1–10) |
| `rateLimitPerSecond` | float | 1.0 | Max requests/sec to same domain (0.1–100) |
| `spiderEnabled` | bool | true | Enable BFS page crawling |
| `maxPagesToSpider` | int | 200 | Page limit for spider (1–10000) |
| `scanFrequency` | string | weekly | manual / daily / weekly / monthly |
| `scanOnEntrySave` | bool | false | Auto-scan entry on save |
| `notificationEmails` | string | '' | Comma-separated recipient list |
| `notificationThreshold` | int | 1 | Min broken links to trigger email |
| `ignorePatterns` | string | '' | Newline-separated regex patterns |
| `userAgent` | string | 'Appleseed Link Checker (Craft CMS)' | HTTP User-Agent header |

## CP Routes & Permissions

### Routes (registered in Plugin.php)

| URL Pattern | Controller Action |
|-------------|-------------------|
| `appleseed` / `appleseed/dashboard` | `dashboard/index` |
| `appleseed/dashboard/detail/<linkId:\d+>` | `dashboard/detail` |
| `appleseed/settings` | `settings/index` |

### AJAX Endpoints (DashboardController)

| Action | Method | Purpose |
|--------|--------|---------|
| `dashboard/run-scan` | POST | Queue a full scan |
| `dashboard/scan-progress` | GET | Poll running scan status |
| `dashboard/ignore-link` | POST | Set link as ignored (param: `linkId`) |
| `dashboard/unignore-link` | POST | Un-ignore link (param: `linkId`) |
| `dashboard/rescan-link` | POST | Queue re-check (param: `linkId`) |
| `dashboard/export-csv` | GET | Download CSV (param: `status`) |

### Permissions

- `appleseed-viewDashboard` — View broken link dashboard
- `appleseed-runScans` — Run link scans, ignore/rescan links
- `appleseed-manageSettings` — Manage plugin settings

## Console Commands

```bash
craft appleseed/scan                          # Full scan (synchronous, stdout progress)
craft appleseed/scan/entry --id=123 [--site-id=1]  # Single entry scan
craft appleseed/check-url https://example.com       # Check one URL, report status
```

## Event Listeners (Plugin.php)

| Event | When | Action |
|-------|------|--------|
| `UrlManager::EVENT_REGISTER_CP_URL_RULES` | CP request | Register 4 CP routes |
| `UserPermissions::EVENT_REGISTER_PERMISSIONS` | CP request | Register 3 permissions |
| `Entry::EVENT_AFTER_SAVE` | Always (if enabled) | Queue `ScanEntryJob` (skips drafts/revisions) |
| On init (CP only) | CP request | Check if scheduled scan is due, push `ScanJob` |

## Working With This Codebase

### Adding a new field type to LinkExtractor

Add a case in `_extractFromField()` (src/services/LinkExtractor.php). Pattern:

```php
if ($field instanceof YourFieldType) {
    $url = /* extract URL from $value */;
    $this->_addLink($url, $entry, $handle, $linkText);
    return;
}
```

`_addLink()` handles normalization, scheme filtering, and relative URL resolution.

### Adding a new link status

1. Update `ScanResult` helper methods if the new status should be considered broken/working
2. Add CSS class in `appleseed.css` (`.appleseed-status--yourstatus`)
3. Update `Reporting::getBrokenLinkCount()` if it should count as broken
4. Add to filter dropdown in `dashboard/_index.twig`

### Adding a new setting

1. Add property to `Settings.php` with default
2. Add validation rule in `defineRules()`
3. Add form field in `settings/_index.twig` using `forms.*Field()` macro
4. Add mapping in `SettingsController::actionSave()`

### Adding a new controller action

1. Add method to `DashboardController` (use `requirePermission()`, `requirePostRequest()` as needed)
2. For AJAX: use `$this->requireAcceptsJson()` and return `$this->asJson([...])`
3. Add JS handler in `appleseed.js` using `Craft.sendActionRequest()`

### Modifying the database schema

1. Bump `Plugin::$schemaVersion`
2. Create a new migration in `src/migrations/` (e.g., `m240101_000000_add_column.php`)
3. Update corresponding ActiveRecord class if needed

## Testing Checklist

1. Install: `composer require` with path repo → `craft plugin/install appleseed` → verify 3 tables
2. Settings: All fields render, validate, and persist
3. Console: `craft appleseed/scan` discovers and checks links
4. Console: `craft appleseed/check-url https://httpstat.us/404` reports broken
5. Dashboard: Summary cards, table, filters, pagination
6. Detail: Click link → see all source locations
7. AJAX: Ignore, un-ignore, rescan buttons work
8. CSV: Download contains correct columns and data
9. Entry save: Enable setting → edit entry → verify queue job
10. Email: Configure recipients → scan with broken links → verify email
11. Scheduling: Set frequency → verify `ScanJob` pushed on next CP load
12. Badge: Broken link count visible on CP nav item
