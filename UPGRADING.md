# Upgrading Appleseed from Craft 5 to Craft 6

Appleseed 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2
adapter. Your links, their results, where they were found, scan history and settings carry over:
Appleseed's tables are unchanged.

> **Beta.** Appleseed 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site
> first. Appleseed 5 remains the stable release for Craft 5.

## For site owners

### Before you start

| | Appleseed 5.x | Appleseed 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.0+ (5.12.1+ for MCP) | 6.0.0-beta.1+ |
| PHP | 8.2+ | 8.5+ |
| Install | `php craft plugin/install appleseed` | `php artisan craft:plugin:install appleseed` |
| Scan from the CLI | `php craft appleseed/scan` | `php artisan appleseed:scan` (the old name still works via `php craft`) |
| Scheduled scans | Control panel visits | Laravel's scheduler, with control panel visits as a fallback |
| Config file | `config/appleseed.php` | `config/craft/appleseed.php` |

1. **Update to Appleseed 5.3.0 first**, while the site is still on Craft 5, and run its migration.
   Appleseed 6 requires it (`$minVersionRequired`), so the install migration can describe the final
   schema:

   ```bash
   composer require justinholtweb/craft-appleseed:^5.3
   php craft up
   ```

2. **Let any running scan finish, or cancel it** (`php craft appleseed/scan/cancel`). A scan's jobs
   on the Craft 5 queue don't move to Laravel's queue.

### Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), and require
Appleseed 6 at the same time as Craft:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-appleseed:^6.0@beta" -W
php artisan craft:up
```

### What carries over

- **Links, sources and scans.** `appleseed_links`, `appleseed_link_sources` and `appleseed_scans`
  keep their rows (schema 1.2.0, unchanged), so ignored links stay ignored, "broken since" dates
  survive and "newly broken" counts continue across the upgrade.
- **Settings.** Every 5.3.0 key is the same in project config, including `navLabel`, `navIcon` and
  `navIconSvg`.
- **Config file.** Move `config/appleseed.php` to `config/craft/appleseed.php`. Same keys.
- **Permissions.** Same keys: `appleseed-viewDashboard`, `appleseed-runScans`,
  `appleseed-manageSettings`. Craft 5 stored permission names lowercased and Craft 6 compares them
  case-insensitively, so users and groups keep exactly what they had; nothing needs re-granting.
  (Microscope's port could keep its keys for the same reason.)
- **Control panel URLs**: `appleseed/dashboard`, `appleseed/dashboard/detail/<id>`,
  `appleseed/settings`, `appleseed/preview-email`. Links in old report emails and Slack messages
  still work.
- **GraphQL**: the same queries, types and `appleseedLinks:read` scope; schemas keep the scope.
- **MCP tools**: the same eleven names and output, so approvals carry over.
- **Webhook body and signature**: unchanged (`X-Appleseed-Event`, `X-Appleseed-Signature`).

### What changed

- **Scheduled scans** run on Laravel's scheduler. If the site doesn't run it yet, add Laravel's cron
  line (`* * * * * php artisan schedule:run`). Without it, control panel visits still start due
  scans, as before.
- **Queued scans** are a Laravel job batch. They run on any worker for the `default` queue
  (`php artisan queue:work`), or from the control panel when `runQueueAutomatically` is on. Several
  workers can now share one scan; the per-host rate limit holds across all of them.
- **Settings** use Craft 6's standard settings screen (**Settings → Plugins → Appleseed**).
- **The entry sidebar** is a "Links" row in the entry editor's details.
- **Logging** goes to an `appleseed` channel (`storage/logs/appleseed-*.log`).
- **Scan history** older than 90 days is pruned daily (`scanHistoryDays`). Set it higher to keep
  more; links and their results are never pruned.

## For developers

### Namespace and classes

`justinholtweb\appleseed` is now `JustinHoltWeb\Appleseed`, laid out the Laravel way:

| Appleseed 5 | Appleseed 6 |
| --- | --- |
| `services\Scanner`, `LinkChecker`, `LinkExtractor`, `Spider`, `Reporting`, `Notifier` | `Services\…` (same names), resolved from the container: `app(Scanner::class)` |
| `Plugin::getInstance()->scanner` | `app(\JustinHoltWeb\Appleseed\Services\Scanner::class)` |
| `records\LinkRecord`, `LinkSourceRecord`, `ScanRecord` | Eloquent `Models\Link`, `Models\LinkSource`, `Models\Scan` |
| `models\ScanResult` (a check's result) | `Data\CheckResult` |
| `models\Settings` | `Models\Settings` (a `PluginSettings`) |
| `helpers\LinkStatus`, `ScanType`, `UrlGuard`, `Honeypot`, `LinkText`, `LinkUrl`, `Presenter` | `Support\…` |
| `jobs\ScanJob`, `CheckLinksBatchedJob`, `CheckLinksJob`, `ScanEntryJob` | `Jobs\StartScan`, `CheckLinkChunk` (batched), `CheckLinks`, `ScanEntry` |
| `widgets\BrokenLinksWidget` | `Widgets\BrokenLinksWidget` |
| `gql\…` | `Gql\GqlAccess`, `Gql\Queries\AppleseedQueries`, `Gql\Types\AppleseedTypes` |
| `mcp\AppleseedTools` | `Mcp\AppleseedTools` |

### Events

Yii events are Laravel events, in `JustinHoltWeb\Appleseed\Events`:

| Appleseed 5 | Appleseed 6 |
| --- | --- |
| `Event::on(LinkExtractor::class, LinkExtractor::EVENT_DEFINE_FIELD_LINKS, fn (DefineFieldLinksEvent $e) => …)` | `Event::listen(DefiningFieldLinks::class, fn (DefiningFieldLinks $e) => …)`. Same properties: `entry`, `owner`, `field`, `value`, `links`, `skipDefault`. |
| `Event::on(Scanner::class, Scanner::EVENT_AFTER_SCAN, fn (ScanEvent $e) => …)` | `Event::listen(ScanCompleted::class, fn (ScanCompleted $e) => …)`. `$e->scan` is a `Models\Scan`. |
| — | `LinkBroke` (new): `$link`, `$result`, `$scanId`, when a check finds a link newly broken. |

### Console commands

| Appleseed 5 (`php craft …`, still works) | Appleseed 6 (`php artisan …`) |
| --- | --- |
| `appleseed/scan` | `appleseed:scan` |
| `appleseed/scan/incremental` | `appleseed:scan --type=incremental` |
| `appleseed/scan/recheck-broken` | `appleseed:scan --type=recheck` |
| `appleseed/scan/entry --entry-id=123` | `appleseed:scan:entry --entry-id=123` |
| `appleseed/scan/status` | `appleseed:scan:status` |
| `appleseed/scan/cancel` | `appleseed:scan:cancel` |
| `appleseed/scan/prune --days=90` | `appleseed:scan:prune --days=90` (and `model:prune` daily) |
| `appleseed/scan/preview-email`, `appleseed/scan/test-email` | `appleseed:scan:email [--output=…]`, `--send [--to=…]` |
| `appleseed/links/export` | `appleseed:links:export` |
| `appleseed/links/recheck 42` | `appleseed:links:recheck 42` |
| `appleseed/check-url <url>` | `appleseed:check-url <url>` |
| — | `appleseed:scan:scheduled` (the scheduler's hourly check) |

`--queue` works on `appleseed:scan` and `appleseed:scan:entry`. Exit codes are Symfony's: 0 success,
1 failure (or a broken link, for `check-url` and `links:recheck`), 2 invalid input.

### Config

`config/craft/appleseed.php` returns the same array as `config/appleseed.php` did. To send
Appleseed's log messages somewhere other than `storage/logs/appleseed-*.log`, define an `appleseed`
channel in `config/logging.php`.
