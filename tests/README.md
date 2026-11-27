# Tests

Pest 5 on `CraftCms\Cms\Plugin\Testing\PluginTestCase`: every test boots a real Craft 6 install with
Appleseed installed, on in-memory SQLite (see `phpunit.xml`), inside a rolled-back transaction. HTTP
is faked (`Http::preventStrayRequests()`), DNS goes through `UrlGuard::resolveUsing()` (`*.internal`
resolves to 10.0.0.5, everything else to a public address), and `Sleep::fake(syncWithCarbon: true)`
makes backoff and the per-host rate limit advance Carbon's clock instead of waiting.

- `Unit/`: settings, UrlGuard, statuses, link text, URL normalising and resolving, the nav item.
- `Feature/`: scans end to end on real entries (fields, Link, Matrix), source replacement and
  pruning, newly-broken counts, the batch, the lock, cancelling; the link checker against faked
  responses; GraphQL with and without the scope; the MCP tools as different users; notifications,
  the webhook and its signature, scheduling and the console; the control panel routes,
  permissions, entry sidebar and widget; the install migration over 5.3 rows.

```bash
vendor/bin/pest
```
