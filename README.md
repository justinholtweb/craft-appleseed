# Appleseed - Broken Link Checker for Craft CMS 5

Appleseed proactively discovers and checks every link across your Craft CMS site, helping you find and fix broken links before your visitors do.

## Features

- **Hybrid link discovery** -- scans entry fields in the database *and* spiders rendered pages
- **Smart HTTP checking** -- HEAD-first with GET fallback, retries with exponential backoff, per-domain rate limiting
- **CP Dashboard** -- summary cards, filterable results table, detail views, ignore/rescan actions
- **Scheduled scans** -- daily, weekly, or monthly automatic scanning
- **Entry-save scanning** -- optionally check links whenever an entry is saved
- **Email notifications** -- get notified when broken links exceed your threshold
- **CSV export** -- download results for offline review
- **Ignore patterns** -- regex-based URL exclusion
- **Console commands** -- `craft appleseed/scan` for CLI/cron usage
- **Badge count** -- broken link count shown in the CP nav

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

```bash
composer require justinholtweb/craft-appleseed
php craft plugin/install appleseed
```

## Console Commands

```bash
# Run a full scan
php craft appleseed/scan

# Scan a single entry
php craft appleseed/scan/entry --id=123

# Check a single URL
php craft appleseed/check-url https://example.com
```

## Configuration

Visit **Settings > Appleseed** in the control panel, or create a `config/appleseed.php` file:

```php
<?php

return [
    'checkExternalLinks' => true,
    'timeout' => 10,
    'maxRetries' => 3,
    'rateLimitPerSecond' => 1.0,
    'spiderEnabled' => true,
    'maxPagesToSpider' => 200,
    'scanFrequency' => 'weekly', // manual, daily, weekly, monthly
    'scanOnEntrySave' => false,
    'notificationEmails' => '',
    'notificationThreshold' => 1,
    'ignorePatterns' => '',
    'userAgent' => 'Appleseed Link Checker (Craft CMS)',
];
```

## License

MIT
