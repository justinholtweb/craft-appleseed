# Appleseed for Craft CMS 6

> **Beta.** This is Appleseed 6, a native Craft 6 plugin (Laravel, no Yii 2 adapter), in beta like
> Craft 6 itself. Appleseed 5 remains the stable release for Craft 5. Upgrading? See
> [UPGRADING.md](UPGRADING.md).

Appleseed finds every link across your Craft site, checks it, and tells you what's broken before
your visitors do.

## Features

- **Hybrid link discovery**: reads entry fields in the database *and* crawls the rendered pages.
  CKEditor and other rich text, Link, Table and Matrix fields, CKEditor nested entries (recorded
  against the entry you can open, with the field path, e.g. `blocks.body`), and third-party link
  fields whose values know their URL (Hyper, Typed Link and similar).
- **Careful checking**: HEAD first with a GET fallback, retries with exponential backoff, a cookie
  jar per link, several hosts at once but never faster than the per-host rate limit, and bot
  protection (Cloudflare, Akamai, LinkedIn's 999) reported as *blocked*, not broken.
- **Safe by default**: only `http`/`https` URLs on your own sites or public addresses are requested,
  checked again at every redirect hop. Craft Black Hole's trap is never touched.
- **Dashboard**: summary, the entries and hosts with the most broken links, filters by status, site,
  host and entry, link detail, ignore / re-check, CSV export that follows the filters.
- **Entry sidebar**: each entry's broken links, redirects and blocked links in the entry editor,
  with *Check this entry again*.
- **Broken Links dashboard widget.**
- **Scan types**: full, content only, changed entries (incremental), re-check broken links, chosen
  sections, one entry. Cancel a running scan.
- **Scheduled scans** on Laravel's scheduler, with a control panel fallback for hosting without one.
- **Notifications**: email, Slack, control panel notifications, and a signed JSON webhook;
  optionally only for links that broke since the last scan.
- **GraphQL** (read-only, never on the public schema) and **MCP** tools for AI assistants.
- **Console commands** for everything, under Artisan and their Craft 5 names.

## Requirements

- Craft CMS 6.0.0-beta.1 or later
- PHP 8.5 or later
- A queue worker, or Craft's `runQueueAutomatically` (the default), for queued scans

## Installation

```bash
composer require "justinholtweb/craft-appleseed:^6.0@beta"
php artisan craft:plugin:install appleseed
```

Then open **Appleseed** in the control panel and run a scan.

## Laravel integrations

Appleseed 6 is written against Laravel directly rather than through Craft's Yii compatibility layer.
Each integration replaced something Appleseed 5 did by hand, and each was chosen for a reason:

| Integration | Where | Why |
|---|---|---|
| **HTTP client** with `Http::pool()` | `Services/LinkChecker` | One request per host per round, several hosts at once. A scan of mostly external links finishes in a fraction of the time, while no single host sees more than the configured rate. Laravel's client also gives `Http::fake()` for tests and `withOptions()` for Guzzle's `on_redirect` (the SSRF guard at every hop), `on_stats` (LinkedIn's 999) and per-link cookie jars. |
| **Cache-backed per-host throttle** | `Support/HostThrottle` | A "next free slot" per host in the cache, taken under a short `Cache::lock`, so the limit holds across *all* queue workers checking one scan. Not `RateLimiter`, whose fixed windows let two requests through milliseconds apart either side of a window edge. Waiting goes through `Sleep`, so tests don't wait. |
| **Queue batching** (`Bus::batch()`) | `Services/Scanner::dispatchChecks()`, `Jobs/CheckLinkChunk` | A scan's links are checked in chunks of `scanBatchSize` as one batch: several workers can share a scan, progress comes from the batch, `finally` finalises the scan, and cancelling cancels the batch. Replaces Appleseed 5's self-re-queuing job. Craft 6 creates `job_batches` on install and upgrade. |
| **Scheduler** | `Plugin::schedule()`, `Console/Commands/ScanScheduledCommand` | `appleseed:scan:scheduled` runs hourly and queues a scan only when one is due, `withoutOverlapping()`. Control panel visits remain a fallback (at most one look a minute) for hosting without cron. |
| **Atomic cache lock** | `Services/Scanner` (`appleseed:scan`) | `Cache::lock()` with an owner token carried through the queue, so two site scans can never start at once, from the dashboard, the console, MCP and the schedule alike, even on separate servers. |
| **Notifications** | `Notifications/BrokenLinksFound`, `Notifications/Channels/SlackWebhookChannel`, `Notifications/BrokenLinksNotice`, `Mail/ScanReportMail` | One queued, retried notification for email (a `CraftMailable`, so Craft's email settings apply) and Slack, sent on demand per recipient so a broken mailer never costs the Slack post; plus Craft's own control panel notifications. |
| **Events and listeners** | `Events/ScanCompleted`, `Events/LinkBroke`, `Events/DefiningFieldLinks`, `Listeners/*` | Plain Laravel events replace Yii's `Event::on()`. Appleseed's own notifications and webhook are listeners on `ScanCompleted`, so anything else can listen the same way. |
| **Queued webhook job** | `Jobs/SendScanWebhook` | The webhook is sent from the queue with `tries` and `backoff`, and signed with HMAC-SHA256. A slow endpoint can't hold up a scan. |
| **Eloquent models with scopes** | `Models/Link`, `Models/LinkSource`, `Models/Scan` | `broken()`, `notIgnored()`, `withStatus()`, `forSite()`, `forHost()`, `forEntry()` and `filtered()` are the one definition of every listing filter, shared by the dashboard, CSV, widget, GraphQL and MCP. |
| **Prunable scans** | `Models/Scan`, `model:prune` on the scheduler | Scan history older than `scanHistoryDays` (90) is pruned daily by Laravel itself. Links and their results are kept. |
| **Log channel** | `appleseed` (`storage/logs/appleseed-*.log`) | Appleseed's messages in their own daily file, unless `config/logging.php` defines an `appleseed` channel, to send them to Slack, Papertrail or a stack. |
| **Artisan commands** | `Console/Commands` | Laravel's console, with the Craft 5 names kept as aliases (`php craft appleseed/scan/status`). |

## Console commands

```bash
php artisan appleseed:scan                         # full scan here (add --queue to queue it)
php artisan appleseed:scan --type=incremental      # entries changed since the last scan
php artisan appleseed:scan --type=recheck          # links already broken, reading no content
php artisan appleseed:scan --type=database         # every entry, without the spider
php artisan appleseed:scan:entry --entry-id=123 [--site-id=1] [--queue]
php artisan appleseed:scan:status [--json]         # totals, the running scan, recent scans, worst hosts
php artisan appleseed:scan:cancel                  # stop the running scan (results so far are kept)
php artisan appleseed:scan:scheduled               # queue the scheduled scan, if one is due
php artisan appleseed:scan:prune --days=90         # delete old scan history now
php artisan appleseed:links:export --status=broken [--host=example.com] [--site-id=1] [--file=broken.csv]
php artisan appleseed:links:recheck 42             # check one known link again (non-zero exit if broken)
php artisan appleseed:check-url https://example.com [--json]   # check any URL, storing nothing
php artisan appleseed:scan:email [--output=report.html]        # preview the report email
php artisan appleseed:scan:email --send [--to=me@example.com]  # send it as a test
```

The Craft 5 names still work through Craft's binary: `php craft appleseed/scan`,
`appleseed/scan/incremental`, `appleseed/scan/recheck-broken`, `appleseed/scan/entry`,
`appleseed/scan/status`, `appleseed/scan/cancel`, `appleseed/scan/prune`, `appleseed/links/export`,
`appleseed/links/recheck`, `appleseed/check-url`, `appleseed/scan/preview-email` and
`appleseed/scan/test-email`.

## Configuration

Settings live under **Settings → Plugins → Appleseed** (admins), or in `config/craft/appleseed.php`:

```php
<?php

return [
    'checkExternalLinks' => true,
    'timeout' => 10,
    'maxRetries' => 3,
    'rateLimitPerSecond' => 1.0,      // per host, across every queue worker
    'spiderEnabled' => true,
    'maxPagesToSpider' => 200,
    'scanBatchSize' => 50,            // links per queued checking job
    'scanFrequency' => 'manual',      // manual, daily, weekly, monthly
    'scheduledScanType' => 'full',    // full, incremental
    'scanOnEntrySave' => false,
    'notificationEmails' => '',
    'notificationThreshold' => 1,
    'notifyOnlyNew' => false,         // only notify about links that broke since the last scan
    'notifyCpUsers' => false,         // also notify, in the CP, everyone who can run scans (new in 6)
    'slackWebhookUrl' => '$APPLESEED_SLACK_WEBHOOK',
    'webhookUrl' => '',
    'webhookSecret' => '',
    'emailLayoutTemplate' => '',
    'ignorePatterns' => '',           // one regex per line
    'excludedFields' => '',           // field handles whose links aren't collected
    'userAgent' => 'Appleseed Link Checker (Craft CMS)',
    'defaultStatusFilter' => '',      // '', broken, redirect, working, server_error, timeout, dns_error, blocked, unknown, ignored
    'navLabel' => null,
    'navIcon' => null,
    'navIconSvg' => null,
    'scanHistoryDays' => 90,          // new in 6
];
```

`notificationEmails`, `userAgent`, `emailLayoutTemplate`, `slackWebhookUrl`, `webhookUrl` and
`webhookSecret` accept environment variables. A variable that isn't set on an environment leaves
that feature off there.

Only admins change settings. **Appleseed → Settings** shows them read-only to holders of *Manage
Appleseed settings*, because settings are project config, including the template that lays out the
report email.

### Scheduled scans

Scans are manual by default. Pick a `scanFrequency` and Appleseed queues a scan when it's due,
measured from the last completed scan that read every entry (so a scan of one saved entry never
pushes it back). It never cold-starts: run one scan to start the clock.

Two things can start a due scan:

- **Laravel's scheduler** (recommended): `appleseed:scan:scheduled` is registered hourly. A site
  that runs the scheduler needs nothing else:

  ```bash
  * * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
  ```

- **Control panel visits**, for hosting without a scheduler, as in Appleseed 5: after a CP
  response has gone out, at most once a minute.

The scan lock means the two can never start a scan each.

### Notifications

After a scan finishes (except a scan of one saved entry), Appleseed emails each notification
address, posts to Slack and, with `notifyCpUsers`, notifies everyone who can run scans in the
control panel, when the number of broken links reaches the threshold (or, with `notifyOnlyNew`, the
number that broke during that scan).

The webhook is sent after every completed scan: a `POST` with a JSON body (`event`, `site`,
`dashboardUrl`, `scan`, `summary`, up to 100 `brokenLinks`) and `X-Appleseed-Event: scan.completed`.
With a secret, the body is signed as `X-Appleseed-Signature: sha256=<hex HMAC-SHA256 of the body>`.
It's sent from the queue and retried with backoff (10s, 1m, 5m, 15m).

### What gets checked

Appleseed only requests `http` and `https` URLs whose host is one of your Craft sites or resolves to
public addresses. Links to `localhost`, private networks (`10.x`, `192.168.x`…) or cloud metadata
endpoints are recorded as not checked, with the reason, and a redirect into one of those is stopped
at the hop. Your own sites' hosts are always checked, even when they resolve to a private address.
URLs matching an ignore pattern are never requested, and the spider won't crawl them.

## Permissions

- **View broken link dashboard** (`appleseed-viewDashboard`): the dashboard, link details, CSV, the
  widget and the entry sidebar row.
- **Run link scans** (`appleseed-runScans`): start, cancel and re-check; ignore links.
- **Manage Appleseed settings** (`appleseed-manageSettings`): see the settings, read-only.

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
`search`, `host`, `siteId`, `entryId` and `includeIgnored`. A source's `entry` resolves through
Craft's own entry resolver, so it's null unless the schema can read that entry's section and site.
The schema is the same as Appleseed 5.3's.

## MCP

Appleseed adds eleven tools to Craft's MCP server (`php artisan craft:mcp:serve`, Craft's built-in
assistant, or the HTTP endpoint). Each acts as the signed-in user and needs control panel access
plus the same permission as the control panel:

| Tool | Permission | |
|------|-----------|-|
| `appleseed_status` | View dashboard | Totals, latest and running scan, worst hosts and entries, schedule |
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

Read-only tools are marked `readOnlyHint`, so they're available with the `mcp:read` scope. Tool
names and output are the same as Appleseed 5.3's.

## Extending

```php
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Appleseed\Events\DefiningFieldLinks;
use JustinHoltWeb\Appleseed\Events\LinkBroke;
use JustinHoltWeb\Appleseed\Events\ScanCompleted;

// Collect links from a field type Appleseed doesn't know
Event::listen(DefiningFieldLinks::class, function (DefiningFieldLinks $event) {
    if ($event->field instanceof MyField) {
        $event->links[] = ['url' => $event->value->href, 'linkText' => $event->value->label];
        $event->skipDefault = true;
    }
});

// React to a finished scan
Event::listen(ScanCompleted::class, function (ScanCompleted $event) {
    // $event->scan->brokenCount, ->newBrokenCount, ->type…
});

// React the moment a link breaks (not again while it stays broken)
Event::listen(LinkBroke::class, function (LinkBroke $event) {
    // $event->link->url, $event->result->statusCode, $event->scanId
});
```

## Sidebar label and icon

**Settings → Plugins → Appleseed → Control panel**: a label, an icon from Craft's icon picker
(custom icon sets included), or a pasted SVG, sanitised every time it's drawn. An override in
Craft's own **Settings → Control Panel Navigation** wins. The badge counts broken links.

## Development

Pest on Craft's `PluginTestCase`: a real Craft 6 install with Appleseed, on in-memory SQLite, with
HTTP faked.

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

## License

[The Craft License](LICENSE.md).
