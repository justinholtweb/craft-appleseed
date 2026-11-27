# Changelog

## 6.0.0-beta.1

Appleseed for Craft CMS 6, rebuilt as a native Craft 6 plugin. It runs on Laravel and has no
dependency on the Yii 2 adapter. Craft 6 is in beta, and so is this release. Update to Appleseed
5.3.0 before upgrading Craft, and see [the upgrade guide](https://github.com/justinholtweb/craft-appleseed/blob/6.0.0/UPGRADING.md).

Everything in 5.3.0 carries over: database and spider discovery, the link checker and its SSRF
guard, every scan type, source replacement and pruning, newly-broken tracking, cancelling, the
dashboard, link detail, entry sidebar, widget, notifications, webhook, GraphQL (same schema), MCP
(same eleven tools and output), the console commands and every setting.

### Added

- **Faster checking.** Links are checked with Laravel's HTTP client, several hosts at once
  (`Http::pool()`), one request per host per round, and never faster than `rateLimitPerSecond` to
  any one host. The rate limit is now shared through the cache by every queue worker checking a
  scan, not counted per process.
- **Scans as Laravel job batches.** A queued scan's links are checked as a `Bus::batch()` of
  `scanBatchSize`-link jobs that several queue workers can share. The dashboard's progress comes
  from the batch, and cancelling a scan cancels it.
- **One scan at a time, guaranteed.** Starting or queueing a site scan takes an atomic cache lock,
  so the dashboard, the console, MCP and the schedule can't start two at once, even on separate
  servers. A queued scan that hasn't started yet counts.
- **Scheduled scans on Laravel's scheduler**: `appleseed:scan:scheduled` runs hourly and queues a
  scan when one is due. Control panel visits still start due scans on hosting without a scheduler.
- **Scan history pruning** with Laravel's `model:prune`, daily, after `scanHistoryDays` (new
  setting, default 90). Links and their results are kept.
- **Control panel notifications** (`notifyCpUsers`, new setting): everyone who can run link scans is
  notified in the control panel when a scan crosses the threshold.
- The webhook is sent from the queue and retried with backoff (10s, 1m, 5m, 15m). Email and Slack
  are a queued, retried Laravel notification.
- Laravel events: `ScanCompleted`, `DefiningFieldLinks` and the new `LinkBroke`, dispatched the
  moment a check finds a link newly broken.
- An `appleseed` log channel (`storage/logs/appleseed-*.log`), which `config/logging.php` can
  redefine.
- `--json` on `appleseed:scan:status` and `appleseed:check-url`; `--queue` on
  `appleseed:scan:entry`.
- The dashboard filters by entry (`?entryId=`), linked from the entry sidebar, and the results table
  sorts by URL, status, code and last checked.

### Changed

- Console commands are Artisan commands: `php artisan appleseed:scan [--type=…] [--queue]`,
  `appleseed:scan:entry`, `:status`, `:cancel`, `:scheduled`, `:prune`, `appleseed:links:export`,
  `appleseed:links:recheck` and `appleseed:check-url`. The Craft 5 names (`php craft
  appleseed/scan/incremental` …) still work.
- The settings use Craft 6's standard plugin settings screen; Appleseed → Settings shows them
  read-only to holders of “Manage Appleseed settings”. Every key is unchanged.
- The entry sidebar panel is a “Links” row in the entry editor's metadata (Craft 6's editor has no
  sidebar-HTML hook), with the same contents and “Check this entry again”.
- Extending: `LinkExtractor::EVENT_DEFINE_FIELD_LINKS` is the `DefiningFieldLinks` event and
  `Scanner::EVENT_AFTER_SCAN` is `ScanCompleted`, both in `JustinHoltWeb\Appleseed\Events`.
- The spider's page limit counts every page it fetches, not only pages that turned out to be HTML.
- `appleseed/scan/preview-email` and `appleseed/scan/test-email` are `appleseed:scan:email`
  (`--output=…`, or `--send [--to=…]`); the old names still work.
- PHP 8.5 and Craft 6.0.0-beta.1 or later.

### Removed

- The Craft 5 `Url` field type no longer exists in Craft 6 (Craft 5.3 converted URL fields to Link
  fields), so its special case is gone; Link fields are read as before.

## 5.3.0 - 2026-11-26

> {note} Notifications are no longer sent after a scan of a single entry (the scan that runs when an entry is saved with “Scan on entry save” on). Before, every save that touched a broken link emailed everyone on the list.

### Added

- **MCP tools.** On Craft 5.12.1 and later, Appleseed adds eleven tools to Craft's MCP server, so an AI assistant can read link results, look up an entry's links, check a URL before it goes into content, run, cancel and follow scans, and ignore links — as the signed-in user, with the same permissions as the control panel. Nothing is registered on earlier Craft versions.
- **GraphQL.** `appleseedLinks`, `appleseedLinkCount`, `appleseedLink`, `appleseedSummary`, `appleseedScans` and `appleseedScan`, behind a new “Query Appleseed links and scans” scope and never on the public schema. Links filter by status, host, URL, site and entry; a source's `entry` resolves through Craft's own entry query, so it respects the schema's section access.
- **A custom sidebar label and icon**, in the plugin settings: a label, an icon from Craft's icon picker, or a pasted SVG (sanitised every time it's drawn).
- **Entry sidebar panel.** The entry editor lists the entry's broken links, redirects and blocked links from the last scan that read it, with a “Check this entry again” button.
- **“Broken Links” dashboard widget**, with counts and the most recently broken links, optionally for one site.
- **Scan changed entries** (incremental scans), from the dashboard, `appleseed/scan/incremental`, or as the scheduled scan type.
- **Re-check broken links** without reading any content, from the dashboard or `appleseed/scan/recheck-broken`.
- **Cancel a running scan**, from the dashboard or `appleseed/scan/cancel`.
- **Slack notifications** and a **signed JSON webhook** after every scan.
- **“Only newly broken links”** notifications. Appleseed now records when each link started failing, and each scan counts how many links broke during it.
- The dashboard lists the entries and hosts with the most broken links, filters by site and host, and has an Ignored filter for un-ignoring. The CSV export follows the dashboard's filters.
- The link detail screen says who ignored a link and when, and since when it's been broken.
- **Excluded fields**: field handles whose links aren't collected.
- Links in third-party link fields that expose their URL (Hyper, Typed Link and similar) are collected, as are links in CKEditor nested entries.
- `LinkExtractor::EVENT_DEFINE_FIELD_LINKS`, to collect links from other field types, and `Scanner::EVENT_AFTER_SCAN`.
- Console commands `appleseed/scan/status`, `appleseed/scan/prune`, `appleseed/links/export` and `appleseed/links/recheck`, and `--queue` on the scan commands.

### Fixed

- **Link sources piled up.** Every scan added a fresh copy of each place a link was found and nothing removed the old ones, so links showed up as found many times over on the detail screen and in the CSV, and links removed from content stayed on the dashboard forever. Each scan now replaces the sources of what it read, links found nowhere any more are removed (ignored links are kept, so the decision survives), and the update removes the duplicates already stored.
- Links in Matrix fields were recorded against the nested entry, which has no useful title or edit screen, and nested entries were also read a second time on their own. They're now recorded against the entry that owns them, with the field path (`blocks.body`).
- **Scheduled scans never ran on busy sites with “Scan on entry save” on**: the schedule was measured from the last completed scan of any kind, so every entry save pushed it back. It's now measured from the last scan that read the whole site.
- A scan whose queue job died stayed “running” forever, blocking scheduled scans and keeping the dashboard's progress bar up. Scans untouched for two hours are now marked failed.
- Saving the settings threw an error when a setting named an environment variable that isn't set on that environment.
- The dashboard and CSV export no longer run a query (and an entry lookup) per link.
- Credentials in a link (`https://user:password@…`) are no longer stored, shown or sent in notifications.
- Users without “Run link scans” no longer see scan, re-check and ignore buttons that would fail.

## 5.2.6 - 2026-10-25

### Fixed

- **Links behind bot protection were reported as broken.** Cloudflare's bot challenge and Akamai's "Access Denied" page answer the checker with a 403 whatever the link's real state, and LinkedIn answers with a non-standard 999 that showed as broken with no status code. These links now get a new **Blocked** status — not counted as broken, with its own filter — and the link detail says which protection turned the checker away, so you can check them in a browser. ([#10](https://github.com/justinholtweb/craft-appleseed/issues/10))
- **Sign-on redirects were reported as broken.** Sites that set a cookie and redirect back to the same URL (iModules alumni sites, among others) looped until "Will not follow more than 10 redirects", because the checker kept no cookies. Each link is now checked with its own cookie jar. ([#10](https://github.com/justinholtweb/craft-appleseed/issues/10))
- `craft appleseed/check-url` now exits with an error for a 5xx response, matching the dashboard, which already counted server errors as broken.

## 5.2.5 - 2026-10-07

> {warning} Only admins can now save Appleseed's settings — the "Manage Appleseed settings" permission shows them read-only. And links to private or internal addresses (`localhost`, `10.x`, `192.168.x`, cloud metadata and the like) are no longer requested: they're recorded as not checked. Links to your own sites' hosts are still checked wherever those resolve.

### Security

- **The link checker no longer requests private or internal addresses.** Any URL in content was fetched and its status reported, so an editor could point a link at a cloud metadata endpoint or an intranet host and have the server request it. A URL is now refused unless it is `http`/`https` and its host is one of your Craft sites or resolves only to public addresses — checked before the request and again at every redirect, so a public page can't bounce the checker inward. Refused links are recorded as not checked, with the reason.
- **Saving settings requires an admin.** Settings are project config, including the Twig template that lays out the report email, and "Manage Appleseed settings" was enough to change them. That permission now shows the settings read-only; saving needs an admin, on an environment that allows admin changes.

### Fixed

- **Appleseed's permissions survived only CP requests.** They were registered only for control panel requests, and Craft discards permission names it doesn't recognise when saving — so a console save, `project-config/apply` during a deploy included, could strip them from users and groups. They're now registered on every request.
- The Appleseed nav item only lists the screens the user can open; someone with only "Manage Appleseed settings" no longer gets a Dashboard link that 403s, and vice versa.

### Changed

- The dashboard follows the control panel's theme. Its stylesheet used hard-coded light colours; it now uses Craft's colour variables, so it reads correctly in dark mode and high-contrast mode. The status filter is Craft's own select, inline styles are gone from the CP templates, and the search placeholder is translatable.

## 5.2.4 - 2026-10-07

### Fixed

- The settings screen failed to render: an unescaped apostrophe in the Ignore Patterns instructions was a Twig syntax error. Thanks [@rosskenney](https://github.com/rosskenney). ([#9](https://github.com/justinholtweb/craft-appleseed/pull/9))

## 5.2.3 - 2026-09-25

### Fixed

- **Full scans no longer spring Craft Black Hole's trap and get the scanning server banned from its own site.** Black Hole hides a link to its trap on every front-end page; the spider followed it, Black Hole banned the address the scan runs from (`127.0.0.1` on a dev machine), and every page checked after that came back as a 403 and was reported broken. When Black Hole is installed and enabled, its trap path — and everything beneath it — is now never requested by the spider or the link checker, and isn't recorded as a link. ([#7](https://github.com/justinholtweb/craft-appleseed/issues/7))
- Ignore patterns now apply to the spider as well as the link checker. A page matching a pattern is no longer fetched or crawled, so a pattern can keep the spider out of a path.

### Changed

- The spider no longer follows `rel="nofollow"` links through the site. They're still recorded and checked as links; they just aren't crawled for more links.

## 5.2.2 - 2026-08-26

### Fixed

- **The Source column glued the entry title and its ID together**, rendering rows like "Harbour terminal moves to a single scheduling window31279". Twig's `??` binds tighter than `~`, so `a.title ?? 'Entry #' ~ a.id` parses as `(a.title ?? 'Entry #') ~ a.id`. Fixed on both the dashboard and the link detail screen.

## 5.2.1 - 2026-08-14

### Changed

- Scans are no longer started automatically when the plugin is installed or updated. The `scanFrequency` default is now `manual`, and a scheduled scan is never queued until at least one scan has completed — the schedule is measured from your most recent completed scan, so run one from the dashboard (or `php craft appleseed/scan`) to start the clock. Sites that have explicitly saved a frequency and completed a scan keep their existing schedule; sites that were relying on the old `weekly` default without ever saving settings will need to choose a frequency to keep scanning on a schedule.

### Fixed

- The settings screen no longer offers editable fields on environments where Craft's `allowAdminChanges` is disabled. Saving there attempted a project config write and failed with an error; the page now renders read-only with a note pointing at `config/appleseed.php`, and `settings/save` returns a 403 instead of erroring.

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
