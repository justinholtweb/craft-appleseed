# Appleseed — Craft CMS 6 (branch `6.0.0`)

Broken link checker: discovers links from entry fields and rendered pages, checks them over HTTP,
reports results in the control panel, GraphQL and MCP, and notifies by email, Slack, CP
notifications and a signed webhook.

This branch is the native Craft 6 port. **Laravel only: no `craftcms/yii2-adapter`, no `yiisoft/yii2`,
no `craft\`/`yii\` namespaces, no `Craft::`.** `bin/port-check craft-appleseed` in
`~/Sites/plugin-testing-craft6` enforces it. Craft 5 is `main` (5.3.x) in `~/Sites/craft-appleseed`;
this branch is the worktree `~/Sites/craft6-ports/craft-appleseed`. Fixes that apply to both go on
5.x first.

- **Package:** `justinholtweb/craft-appleseed` 6.0.0-beta.1 · **Namespace:** `JustinHoltWeb\Appleseed` ·
  **Handle:** `appleseed` · **Class:** `JustinHoltWeb\Appleseed\Plugin`
- **Craft** `^6.0.0-beta.1` · **PHP** `^8.5`
- **Schema** 1.2.0 (unchanged from 5.3.0) · `$minVersionRequired = '5.3.0'`
- Porting cookbook and harness: `~/Sites/plugin-testing-craft6/{PLAN.md,porting/COOKBOOK.md}`

## Layout

```
src/
├── Plugin.php                 # $commands/$widgets/$gqlQueries/$events/$styles/$scripts, register() singletons,
│                              # boot(CapabilityRegistry), schedule(), getPermissions(), nav, settingsUi()
├── Console/Commands/          # appleseed:scan[:entry|:status|:cancel|:scheduled|:prune|:email], appleseed:links:*, appleseed:check-url
├── Data/CheckResult.php       # one URL's check result (5.x models\ScanResult)
├── Database/Tables.php        # table names (the install migration is required outside the autoloader)
├── Events/                    # ScanCompleted, LinkBroke, DefiningFieldLinks
├── Gql/                       # GqlAccess (scope appleseedLinks:read, never public), Queries/AppleseedQueries (+ resolvers), Types/AppleseedTypes
├── Http/Controllers/          # Dashboard (invokable), Links (detail/ignore/unignore/rescan), Scans (run/cancel/entry/progress), Export, EmailPreview, Settings (read-only)
├── Jobs/                      # StartScan → Bus::batch(CheckLinkChunk…); ScanEntry; CheckLinks; SendScanWebhook
├── Listeners/                 # SendScanNotifications + QueueScanWebhook (ScanCompleted), QueueEntryScan (ElementSaved), AddEntrySidebar (ElementMetadataResolving)
├── Mail/ScanReportMail.php    # CraftMailable
├── Mcp/AppleseedTools.php     # the 11 tools; names and output final, shared with 5.3
├── Models/                    # Eloquent Link, LinkSource, Scan (Prunable); Settings (PluginSettings)
├── Notifications/             # BrokenLinksFound (mail + Slack, queued), BrokenLinksNotice (CP), Channels/SlackWebhookChannel
├── Services/                  # Scanner (orchestrator, lock, batch), LinkExtractor, Spider, LinkChecker (Http::pool), Reporting, Notifier, Schedules
├── Support/                   # LinkStatus, ScanType, UrlGuard, Honeypot, HostThrottle, LinkText, LinkUrl, Presenter
└── Widgets/BrokenLinksWidget.php   # craft:html-widget
routes/cp.php                  # 5.x URL paths, can:<permission> middleware
database/migrations/Install.php
resources/{templates,css,js,icon.svg,icon-mask.svg}
tests/  Pest on PluginTestCase (real Craft 6 + Appleseed on in-memory SQLite, HTTP faked)
```

## Scan workflow

1. `Scanner::queueScan()` takes the `appleseed:scan` cache lock (owner token) and dispatches
   `StartScan`. Console scans call `runScan()`, which takes the lock and works in-process.
2. `startScan()` creates the `Scan` (running), discovers links (`LinkExtractor` over every live section
   entry, nested entries recorded against the owner with a dotted field path; `Spider` on full scans)
   and `storeLinks()`: **replaces** the sources of everything it read (never appends), upserts links by
   `urlHash`, deletes non-ignored links with no sources. `recheck` only tags broken links.
3. `dispatchChecks()` sends a `Bus::batch()` of `CheckLinkChunk` jobs (`scanBatchSize` IDs each); each
   runs `checkChunk()` → `LinkChecker::checkMany()` → `applyResult()` (`firstBrokenAt`, `LinkBroke`).
4. The batch's `finally` → `finishBatchedScan()` → `finalizeScan()` (tallies incl. `newBrokenCount`,
   `ScanCompleted`) and releases the lock. Cancelled scans stay cancelled.

`LinkChecker`: UrlGuard + Honeypot + ignore patterns before any request; `on_redirect` = UrlGuard at
every hop; HEAD then GET on 400/403/405; retries with 1s/2s/4s backoff; cookie jar per link; `on_stats`
keeps non-standard codes (LinkedIn 999 → `blocked`); Cloudflare/Akamai → `blocked`. One request per
host per round, `MAX_HOSTS_AT_ONCE` hosts per `Http::pool()`, each host spaced by `HostThrottle`.

## Key decisions

1. **Same tables, settings keys, permission keys, CP URLs, GraphQL schema and MCP tools as 5.3.0.**
   Permission keys keep their mixed case: Craft 6 compares permissions case-insensitively.
2. **Entry sidebar = `ElementMetadataResolving`** (a "Links" row, closure value). Craft 6's editor has
   no sidebar-HTML hook. The button posts JSON from `resources/js/appleseed.js`.
3. **Batch jobs and Craft's queue manager.** The database queue inserts a batch's jobs in bulk without
   `JobQueued`, so Craft has no `jobprogress` row for them: the web queue runner wouldn't run them and
   `ShouldRun` would delete them. `Plugin::trackBatchedJobs()` adds the rows from a
   `Queue::createPayloadUsing()` hook; `CheckLinkChunk::middleware()` drops `ShouldRun`.
4. **The lock covers site scans only.** Entry scans (after a save) neither take nor respect it, as in
   5.x. `QUEUED_KEY` marks "queued, not started" so the dashboard and `queueScan()` see it.
5. **Scheduling** = hourly `appleseed:scan:scheduled` + the CP-visit fallback (`Schedules::runIfDueFromWeb`),
   both through `queueScan()`'s lock. Measured from the last completed full/database/incremental scan.

## Traps

- **Http::fake() stubs registered first win.** A test that re-fakes after `beforeEach` must
  `Http::swap(new Factory(app('events')))` first, or put its stubs first (`fakeWeb($first)`).
- **`Queue::fake()` breaks Craft's queue listeners** (`QueueFake::getConfig()`); use `Bus::fake([Job::class])`.
- **Batches in tests:** the batch repository's `sqlite` connection is a *different* in-memory DB, and the
  test DB has no `job_batches`. `TestCase::setUp()` points batching at the default connection and runs
  `LaravelMigrations::ensureQueueTables()`.
- **CP pages are Inertia JSON**: `assertSee('a/b')` fails on `a\/b`. Use `cpPage($response)` (tests/Pest.php).
- **Same-second dates:** `dateUpdated >= startedAt` includes entries saved in the scan's second (safe).
- **Translations are ICU:** never put `{{ … }}` inside `t()`.
- **CP JS loads on every page, in `<head>`, before Vue.** Delegate from `document`, no `Craft.*`, send
  `X-XSRF-TOKEN` from the cookie (a POST without it is 419).
- **Colours:** Craft 6 tokens (`--c-color-danger-on-quiet`, `--c-text-quiet`, `--c-surface-*`) with Craft 5
  and plain fallbacks; no inline `style=` in CP templates (emails excepted).

## Testing

```sh
docker exec -w /var/www/ports/craft-appleseed ddev-plugin-testing-craft6-web vendor/bin/pest
docker exec -w /var/www/ports/craft-appleseed ddev-plugin-testing-craft6-web vendor/bin/pint --test
docker exec -w /var/www/ports/craft-appleseed ddev-plugin-testing-craft6-web vendor/bin/phpstan analyse --memory-limit=1G   # level 6
~/Sites/plugin-testing-craft6/bin/port-check craft-appleseed
```

Live checks in the harness: `php artisan appleseed:scan`, `bin/cp-session` + curl (decode the Inertia
JSON before grepping). The harness runs Craft 6.0.0-beta.1; the port's own vendor has beta.2.
