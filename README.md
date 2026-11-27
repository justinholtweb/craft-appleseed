# Appleseed - Broken Link Checker for Craft CMS 5

Appleseed proactively discovers and checks every link across your Craft CMS site, helping you find and fix broken links before your visitors do.

## Features

- **Hybrid link discovery** -- scans entry fields in the database *and* spiders rendered pages. Reads CKEditor, Link, URL, Table, Matrix and CKEditor nested entries (recorded against the entry you can open), and third-party link fields such as Hyper.
- **Smart HTTP checking** -- HEAD-first with GET fallback, retries with exponential backoff, per-domain rate limiting, bot-protection detection
- **CP Dashboard** -- summary cards, the entries and hosts with the most broken links, filters by status, site and host, detail views, ignore/rescan actions
- **Entry sidebar** -- each entry's broken links and redirects in the entry editor, with a button to check it again
- **Dashboard widget** -- broken link counts and the most recently broken links
- **Scan types** -- full, changed entries only (incremental), re-check broken links only, or chosen sections; cancel a running scan
- **Scheduled scans** -- optional daily, weekly, or monthly automatic scanning, full or incremental
- **Entry-save scanning** -- optionally check links whenever an entry is saved
- **Notifications** -- email, Slack and a signed JSON webhook; optionally only for links that broke since the last scan
- **GraphQL** -- read-only queries behind their own scope, never on the public schema
- **MCP** -- tools for Craft's MCP server on Craft 5.12.1+, so AI assistants can read results and run scans as the signed-in user
- **CSV export** -- download results for offline review, honouring the dashboard's filters
- **Ignore patterns and excluded fields** -- regex-based URL exclusion, and fields whose links aren't collected
- **Console commands** -- scans, status, export and housekeeping for CLI/cron usage
- **Badge count** -- broken link count shown in the CP nav, with a configurable label and icon

## Requirements

- Craft CMS 5.0.0 or later (5.12.1 or later for the MCP tools)
- PHP 8.2 or later

## Installation

```bash
composer require justinholtweb/craft-appleseed
php craft plugin/install appleseed
```

## Console Commands

```bash
# Run a full scan (add --queue to queue it instead of running it here)
php craft appleseed/scan

# Scan only the entries changed since the last scan — a good cron target between full scans
php craft appleseed/scan/incremental

# Check the links already known to be broken again, without reading any content
php craft appleseed/scan/recheck-broken

# Scan a single entry
php craft appleseed/scan/entry --entry-id=123 [--site-id=1]

# Totals, the running scan, recent scans and the worst hosts
php craft appleseed/scan/status

# Stop the running scan (results already checked are kept)
php craft appleseed/scan/cancel

# Delete scan history older than 90 days (links and their results are kept)
php craft appleseed/scan/prune --days=90

# Export links as CSV
php craft appleseed/links/export --status=broken [--host=example.com] [--site-id=1] [--file=broken.csv]

# Check one known link again
php craft appleseed/links/recheck 42

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
    'scanBatchSize' => 50,
    'scanFrequency' => 'manual', // manual, daily, weekly, monthly
    'scheduledScanType' => 'full', // full, incremental
    'scanOnEntrySave' => false,
    'notificationEmails' => '',
    'notificationThreshold' => 1,
    'notifyOnlyNew' => false, // only notify about links that broke since the last scan
    'slackWebhookUrl' => '$APPLESEED_SLACK_WEBHOOK',
    'webhookUrl' => '',
    'webhookSecret' => '',
    'emailLayoutTemplate' => '',
    'ignorePatterns' => '',
    'excludedFields' => '', // field handles whose links aren't collected
    'userAgent' => 'Appleseed Link Checker (Craft CMS)',
    // Status the dashboard filters by on first load: '' (all), broken, redirect,
    // working, server_error, timeout, dns_error, blocked, unknown, ignored
    'defaultStatusFilter' => '',
    // The CP sidebar: label, an icon name from Craft's icon picker, or pasted SVG
    'navLabel' => null,
    'navIcon' => null,
    'navIconSvg' => null,
];
```

`notificationEmails`, `userAgent`, `emailLayoutTemplate`, `slackWebhookUrl`, `webhookUrl` and
`webhookSecret` accept environment variables (`$APPLESEED_SLACK_WEBHOOK`). A variable that isn't set
on an environment simply leaves that feature off there.

### Scheduled scans

Scans are manual by default -- nothing is queued when the plugin is installed or updated. Pick a
`scanFrequency` of `daily`, `weekly`, or `monthly` to scan automatically. The schedule is measured
from your most recent completed scan, so run one from the dashboard (or with `php craft
appleseed/scan`) to start the clock.

### Notifications

After a scan finishes (except a scan of one saved entry), Appleseed emails the notification
addresses and posts to Slack when the number of broken links reaches the threshold — or, with
`notifyOnlyNew`, the number of links that broke during that scan.

The webhook is sent after every completed scan: a `POST` with a JSON body
(`event`, `scan`, `summary`, up to 100 `brokenLinks`) and an `X-Appleseed-Event: scan.completed`
header. With a secret, the body is signed as `X-Appleseed-Signature: sha256=<hex HMAC-SHA256>`.

### Read-only settings

When [`allowAdminChanges`](https://craftcms.com/docs/5.x/reference/config/general.html#allowadminchanges)
is disabled for the environment, the settings screen renders read-only: the fields show the active
configuration but can't be edited, and saving is rejected. Set your values in `config/appleseed.php`
on those environments.

Only admins can change settings. Users with the "Manage Appleseed settings" permission see them
read-only, because settings are project config — including the template that lays out the report
email.

### What gets checked

Appleseed only requests `http` and `https` URLs whose host is one of your Craft sites or resolves to
public addresses. Links to `localhost`, private networks (`10.x`, `192.168.x`, …) or cloud metadata
endpoints are recorded as not checked, with the reason, and a redirect into one of those is stopped
at the hop. Your own sites' hosts are always checked, even when they resolve to a private address.

## GraphQL

Give a schema the **Appleseed → Query Appleseed links and scans** scope (`appleseedLinks:read`).
It's never answered on the public schema, whatever its scopes.

```graphql
{
  appleseedSummary { total broken redirects blocked lastScan { completedAt newBrokenCount } }
  appleseedLinkCount(status: "broken")
  appleseedLinks(status: "broken", host: "example.com", limit: 20, orderBy: "firstBrokenAt", direction: "desc") {
    url statusCode errorMessage firstBrokenAt
    sources { fieldHandle linkText entry { title url } }
  }
  appleseedScans(limit: 5) { id type status brokenCount }
}
```

`appleseedLinks` and `appleseedLinkCount` filter by `status` (`broken` covers every broken status),
`search`, `host`, `siteId` and `entryId`. A source's `entry` resolves through Craft's own entry
query, so it's null unless the schema can read that entry's section.

## MCP

On Craft 5.12.1 and later, Appleseed adds tools to Craft's MCP server. Each acts as the signed-in
user and needs the same permissions as the control panel:

| Tool | Permission | |
|------|-----------|-|
| `appleseed_status` | View dashboard | Totals, latest and running scan, worst hosts and entries |
| `appleseed_links_list` | View dashboard | Links with results and sources, filtered and paged |
| `appleseed_link_get` | View dashboard | One link by ID or URL |
| `appleseed_entry_links` | View dashboard + the entry | An entry's links |
| `appleseed_scans_list` | View dashboard | Scan history |
| `appleseed_scan_run` | Run scans | Queue a full, database, incremental or recheck scan |
| `appleseed_scan_cancel` | Run scans | Stop the running scan |
| `appleseed_entry_scan` | Run scans + the entry | Queue a scan of one entry |
| `appleseed_link_recheck` | Run scans | Check a known link now |
| `appleseed_url_check` | Run scans | Check any public URL now, storing nothing |
| `appleseed_link_ignore` | Run scans | Ignore or un-ignore a link |

Read-only tools are marked `readOnlyHint`, so they're available with the `mcp:read` OAuth scope.
On earlier Craft versions nothing is registered.

## Extending

```php
use justinholtweb\appleseed\events\DefineFieldLinksEvent;
use justinholtweb\appleseed\events\ScanEvent;
use justinholtweb\appleseed\services\LinkExtractor;
use justinholtweb\appleseed\services\Scanner;

// Collect links from a field type Appleseed doesn't know
Event::on(LinkExtractor::class, LinkExtractor::EVENT_DEFINE_FIELD_LINKS, function(DefineFieldLinksEvent $event) {
    if ($event->field instanceof MyField) {
        $event->links[] = ['url' => $event->value->href, 'linkText' => $event->value->label];
        $event->skipDefault = true;
    }
});

// React to a finished scan
Event::on(Scanner::class, Scanner::EVENT_AFTER_SCAN, function(ScanEvent $event) {
    // $event->scan->brokenCount, ->newBrokenCount…
});
```

## License

MIT
