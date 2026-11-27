<?php
/**
 * Appleseed 5.3.0's features, checked in the plugin-testing harness (Craft 5.12.1+), partly over
 * HTTP: GraphQL, the MCP tools, notifications, the entry sidebar, the widget, the sidebar label
 * and icon, scan cancellation, and link sources being replaced rather than piling up.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-appleseed/tests/integration/features.php
 *
 * Needs a site with at least one scanned entry link (run `php craft appleseed/scan` first).
 * Restores Appleseed's settings in a fresh process. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use craft\models\GqlSchema;
use craft\models\GqlToken;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\appleseed\events\DefineFieldLinksEvent;
use justinholtweb\appleseed\helpers\LinkUrl;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\mcp\AppleseedTools;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\ScanRecord;
use justinholtweb\appleseed\services\LinkExtractor;
use justinholtweb\appleseed\services\Scanner;
use justinholtweb\appleseed\widgets\BrokenLinksWidget;
use yii\base\Event;
use yii\db\Query;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$password = 'Appleseed-' . bin2hex(random_bytes(6));
$settingsPath = 'plugins.appleseed.settings';
$settingsBefore = Craft::$app->getProjectConfig()->get($settingsPath);
$cleanup = ['users' => [], 'schemas' => [], 'tokens' => [], 'scans' => [], 'server' => null];

register_shutdown_function(function() use (&$cleanup, $settingsPath, $settingsBefore, $root) {
    foreach ($cleanup['tokens'] as $token) {
        Craft::$app->getGql()->deleteTokenById($token->id);
    }
    foreach ($cleanup['schemas'] as $schema) {
        Craft::$app->getGql()->deleteSchema($schema);
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    if ($cleanup['scans']) {
        ScanRecord::deleteAll(['id' => $cleanup['scans']]);
    }
    if (is_resource($cleanup['server'])) {
        proc_terminate($cleanup['server']);
    }

    $restore = sys_get_temp_dir() . '/appleseed-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$pc = Craft::$app->getProjectConfig();
$pc->set(' . var_export($settingsPath, true) . ', ' . var_export($settingsBefore, true) . ', "Restore Appleseed settings after features.php");
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not restore Appleseed's settings: " . implode("\n", $out) . "\n");
});

$user = static function(string $name, array $permissions, bool $admin = false) use (&$cleanup, $run, $password): User {
    $u = new User(['username' => "appleseed-$name-$run", 'email' => "appleseed-$name-$run@example.com", 'newPassword' => $password, 'admin' => $admin]);
    Craft::$app->getElements()->saveElement($u, false);
    Craft::$app->getUsers()->activateUser($u);
    Craft::$app->getUserPermissions()->saveUserPermissions($u->id, $permissions);
    $cleanup['users'][] = $u;

    return User::find()->id($u->id)->one();
};

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return [$http, $csrf];
}

/** Runs an MCP tool as a user, the way Craft's server would: the actor is the signed-in user. */
function asUser(User $user, callable $fn): mixed
{
    Craft::$app->getUser()->setIdentity($user);

    try {
        return $fn(new AppleseedTools(new craft\mcp\McpActor()));
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
}

// A scanned entry with at least one broken link, to test against
$sample = (new Query())
    ->select(['ls.entryId', 'ls.siteId', 'ls.fieldHandle', 'l.id', 'l.url', 'l.host'])
    ->from(['ls' => '{{%appleseed_link_sources}}'])
    ->innerJoin(['l' => '{{%appleseed_links}}'], '[[l.id]] = [[ls.linkId]]')
    ->where(['ls.sourceType' => 'database', 'l.isIgnored' => false, 'l.status' => ['broken', 'dns_error', 'timeout', 'server_error']])
    ->andWhere(['not', ['ls.entryId' => null]])
    ->one();

if (!$sample) {
    echo "No scanned entry has a broken link. Run `php craft appleseed/scan` first.\n";
    exit(1);
}

$entry = Entry::find()->id($sample['entryId'])->siteId($sample['siteId'])->status(null)->one();
$viewer = $user('viewer', ['accesscp', 'accessplugin-appleseed', 'appleseed-viewdashboard', 'viewentries:' . $entry?->getSection()?->uid, 'viewpeerentries:' . $entry?->getSection()?->uid]);
$scanner = $user('scanner', ['accesscp', 'accessplugin-appleseed', 'appleseed-viewdashboard', 'appleseed-runscans']);
$nobody = $user('nobody', ['accesscp']);

echo "\nGraphQL\n";

$gql = static function(string $query, ?string $token = null): array {
    $http = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
    $headers = ['Content-Type' => 'application/json'];
    if ($token) {
        $headers['Authorization'] = "Bearer $token";
    }

    return json_decode((string)$http->post('index.php?p=actions/graphql/api', ['headers' => $headers, 'body' => json_encode(['query' => $query])])->getBody(), true) ?? [];
};

$makeToken = static function(array $scope) use (&$cleanup, $run): string {
    $schema = new GqlSchema(['name' => "Appleseed test $run " . count($cleanup['schemas']), 'scope' => $scope, 'isPublic' => false]);
    Craft::$app->getGql()->saveSchema($schema) or throw new RuntimeException('Could not save schema: ' . json_encode($schema->getErrors()));
    $cleanup['schemas'][] = $schema;

    $accessToken = bin2hex(random_bytes(16));
    $token = new GqlToken(['name' => "Appleseed test $run " . count($cleanup['tokens']), 'accessToken' => $accessToken, 'enabled' => true, 'schemaId' => $schema->id]);
    Craft::$app->getGql()->saveToken($token) or throw new RuntimeException('Could not save token');
    $cleanup['tokens'][] = $token;

    return $accessToken;
};

$section = $entry?->getSection();
$sites = array_map(fn($site) => "sites.{$site->uid}:read", Craft::$app->getSites()->getAllSites());
$withEntries = $makeToken([...$sites, 'appleseedLinks:read', 'sections.' . $section?->uid . ':read']);
$linksOnly = $makeToken([...$sites, 'appleseedLinks:read']);
$noScope = $makeToken([...$sites, 'sections.' . $section?->uid . ':read']);

check('the public schema can’t query links, even with the scope', function() use ($gql, &$cleanup, $sites) {
    $gqlService = Craft::$app->getGql();
    $public = $gqlService->getPublicSchema();
    $scope = $public?->scope;

    if ($public) {
        $public->scope = [...($scope ?? []), ...$sites, 'appleseedLinks:read'];
        $gqlService->saveSchema($public);
    }

    try {
        $result = $gql('{ appleseedSummary { broken } }');
    } finally {
        if ($public) {
            $public->scope = $scope;
            $gqlService->saveSchema($public);
        }
    }

    return !isset($result['data']['appleseedSummary']) ?: json_encode($result);
});

check('a schema without the scope can’t either', function() use ($gql, $noScope) {
    $result = $gql('{ appleseedSummary { broken } }', $noScope);

    return isset($result['errors']) ?: json_encode($result);
});

check('appleseedSummary counts match the dashboard', function() use ($gql, $linksOnly, $plugin) {
    $result = $gql('{ appleseedSummary { total broken redirects working blocked ignored lastScan { id status } } }', $linksOnly);
    $summary = $result['data']['appleseedSummary'] ?? null;
    $expected = $plugin->reporting->getDashboardSummary();

    return $summary !== null && $summary['broken'] === $expected['broken'] && $summary['total'] === $expected['total'] ?: json_encode($result);
});

check('appleseedLinks filters by status and entry, with sources', function() use ($gql, $linksOnly, $sample) {
    $result = $gql(sprintf('{ appleseedLinks(status: "broken", entryId: %d) { id url isBroken sourceCount sources { entryId fieldHandle } } }', $sample['entryId']), $linksOnly);
    $links = $result['data']['appleseedLinks'] ?? [];
    $ids = array_column($links, 'id');

    return in_array((int)$sample['id'], $ids, true)
        && array_reduce($links, fn($ok, $l) => $ok && $l['isBroken'] && $l['sourceCount'] === count($l['sources']), true)
        ?: json_encode($result);
});

check('appleseedLinkCount agrees with appleseedLinks', function() use ($gql, $linksOnly) {
    $result = $gql('{ appleseedLinkCount(status: "broken") appleseedLinks(status: "broken", limit: 100) { id } }', $linksOnly);

    return ($result['data']['appleseedLinkCount'] ?? -1) === count($result['data']['appleseedLinks'] ?? []) ?: json_encode($result);
});

check('appleseedLink by URL', function() use ($gql, $linksOnly, $sample) {
    $result = $gql(sprintf('{ appleseedLink(url: %s) { id host } }', json_encode($sample['url'])), $linksOnly);

    return ($result['data']['appleseedLink']['id'] ?? null) === (int)$sample['id'] ?: json_encode($result);
});

check('a source’s entry resolves when the schema can read its section…', function() use ($gql, $withEntries, $sample, $entry) {
    $result = $gql(sprintf('{ appleseedLink(id: %d) { sources { entryId entry { id title } } } }', $sample['id']), $withEntries);
    $sources = array_values(array_filter($result['data']['appleseedLink']['sources'] ?? [], fn($s) => $s['entryId'] === (int)$sample['entryId']));

    // A disabled entry isn't returned by GraphQL's default status filter either
    if ($entry->getStatus() !== Entry::STATUS_LIVE) {
        return ($sources[0]['entry'] ?? 'missing') === null ?: json_encode($result);
    }

    return ($sources[0]['entry']['id'] ?? null) == $sample['entryId'] ?: json_encode($result);
});

check('…and is null when it can’t', function() use ($gql, $linksOnly, $sample) {
    $result = $gql(sprintf('{ appleseedLink(id: %d) { sources { entryId entry { id } } } }', $sample['id']), $linksOnly);
    $entries = array_column($result['data']['appleseedLink']['sources'] ?? [['entry' => 'missing']], 'entry');

    return $entries !== [] && array_filter($entries) === [] ?: json_encode($result);
});

check('appleseedScans and appleseedScan', function() use ($gql, $linksOnly) {
    $result = $gql('{ appleseedScans(limit: 2) { id type status } appleseedScan { id status } }', $linksOnly);

    return count($result['data']['appleseedScans'] ?? []) > 0 && ($result['data']['appleseedScan']['status'] ?? null) === 'completed' ?: json_encode($result);
});

echo "\nMCP\n";

check('the tools are registered with Craft’s MCP server', function() {
    $names = array_keys(Craft::$app->get('mcp')->getCapabilityRegistry()->definitions()->getTools());
    $ours = array_values(array_filter($names, fn($n) => str_starts_with((string)$n, 'appleseed_')));
    sort($ours);
    $expected = ['appleseed_entry_links', 'appleseed_entry_scan', 'appleseed_link_get', 'appleseed_link_ignore', 'appleseed_link_recheck', 'appleseed_links_list', 'appleseed_scan_cancel', 'appleseed_scan_run', 'appleseed_scans_list', 'appleseed_status', 'appleseed_url_check'];

    return $ours === $expected ?: json_encode($ours);
});

check('appleseed_status for a viewer', function() use ($viewer, $plugin) {
    $status = asUser($viewer, fn(AppleseedTools $t) => $t->status());

    return $status['summary'] === $plugin->reporting->getDashboardSummary() && isset($status['latestScan']['id']) ?: json_encode($status);
});

check('appleseed_status refuses someone without the dashboard permission', function() use ($nobody) {
    try {
        asUser($nobody, fn(AppleseedTools $t) => $t->status());
    } catch (Mcp\Exception\ToolCallException) {
        return true;
    }

    return 'allowed';
});

check('appleseed_links_list pages through broken links with their sources', function() use ($viewer) {
    $page = asUser($viewer, fn(AppleseedTools $t) => $t->listLinks(status: 'broken', limit: 1));
    $link = $page['links'][0] ?? null;

    return $page['count'] === 1 && ($page['total'] > 1 ? $page['nextOffset'] === 1 : $page['nextOffset'] === null) && $link && $link['isBroken'] && isset($link['sources'], $link['cpUrl'])
        ?: json_encode($page);
});

check('appleseed_link_get by URL', function() use ($viewer, $sample) {
    $link = asUser($viewer, fn(AppleseedTools $t) => $t->getLink(url: $sample['url']));

    return $link['id'] === (int)$sample['id'] && $link['sourceCount'] > 0 ?: json_encode($link);
});

check('appleseed_entry_links lists the entry’s problems', function() use ($viewer, $sample) {
    $result = asUser($viewer, fn(AppleseedTools $t) => $t->entryLinks((int)$sample['entryId'], (int)$sample['siteId'], true));

    return in_array((int)$sample['id'], array_column($result['links'], 'id'), true) ?: json_encode($result);
});

check('…but not for an entry the user can’t view', function() use ($scanner, $sample) {
    try {
        asUser($scanner, fn(AppleseedTools $t) => $t->entryLinks((int)$sample['entryId'], (int)$sample['siteId']));
    } catch (Mcp\Exception\ToolCallException) {
        return true;
    }

    return 'allowed';
});

check('a viewer can’t start a scan', function() use ($viewer) {
    try {
        asUser($viewer, fn(AppleseedTools $t) => $t->runScan());
    } catch (Mcp\Exception\ToolCallException) {
        return true;
    }

    return 'allowed';
});

check('appleseed_url_check refuses an internal address without requesting it', function() use ($scanner) {
    $result = asUser($scanner, fn(AppleseedTools $t) => $t->checkUrl('http://169.254.169.254/latest/meta-data/'));

    return $result['status'] === 'ignored' && !$result['isBroken'] ?: json_encode($result);
});

check('appleseed_link_ignore records who ignored the link, and un-ignoring clears it', function() use ($scanner, $sample) {
    $ignored = asUser($scanner, fn(AppleseedTools $t) => $t->ignoreLink((int)$sample['id']));
    $record = LinkRecord::findOne($sample['id']);
    $ok = $ignored['isIgnored'] && (int)$record->ignoredById === (int)$scanner->id && $record->dateIgnored !== null;

    asUser($scanner, fn(AppleseedTools $t) => $t->ignoreLink((int)$sample['id'], false));
    $record->refresh();

    // Put the result back the way it was
    Plugin::getInstance()->scanner->checkLink($record);

    return $ok && !$record->isIgnored && $record->ignoredById === null ?: json_encode($record->getAttributes());
});

echo "\nScans\n";

check('cancelling stops a running scan, and finalising doesn’t complete it', function() use ($plugin, &$cleanup) {
    $scan = new ScanRecord(['status' => Scanner::STATUS_RUNNING, 'type' => ScanType::FULL, 'startedAt' => craft\helpers\Db::prepareDateForDb(new DateTime())]);
    $scan->save(false);
    $cleanup['scans'][] = $scan->id;

    $cancelled = $plugin->scanner->cancelRunningScans();
    $plugin->scanner->finalizeScan($scan);
    $scan->refresh();

    return $cancelled >= 1 && $scan->status === Scanner::STATUS_CANCELLED ?: "cancelled $cancelled, status {$scan->status}";
});

check('a scan whose job died stops counting as running', function() use ($plugin, &$cleanup) {
    $scan = new ScanRecord(['status' => Scanner::STATUS_RUNNING, 'type' => ScanType::FULL]);
    $scan->save(false);
    $cleanup['scans'][] = $scan->id;
    ScanRecord::updateAll(['dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime('-3 hours'))], ['id' => $scan->id]);

    $running = $plugin->reporting->getRunningScan();
    $scan->refresh();

    return $running?->id !== $scan->id && $scan->status === Scanner::STATUS_FAILED ?: "status {$scan->status}";
});

check('re-scanning an entry replaces its sources instead of adding more', function() use ($plugin, $sample, &$cleanup) {
    $count = fn() => (int)(new Query())->from('{{%appleseed_link_sources}}')->where(['entryId' => $sample['entryId'], 'siteId' => $sample['siteId']])->count();
    $before = $count();

    for ($i = 0; $i < 2; $i++) {
        $cleanup['scans'][] = $plugin->scanner->runEntryScan((int)$sample['entryId'], (int)$sample['siteId'])->id;
    }

    return $before > 0 && $count() === $before ?: "before $before, after {$count()}";
});

check('an excluded field’s links aren’t collected', function() use ($plugin, $entry, $sample) {
    $settings = $plugin->getSettings();
    $topField = explode('.', (string)$sample['fieldHandle'])[0];
    $before = $settings->excludedFields;
    $settings->excludedFields = $topField;

    try {
        $links = $plugin->linkExtractor->extractFromEntry($entry);
    } finally {
        $settings->excludedFields = $before;
    }

    $fromField = array_filter($links, fn($l) => $l['fieldHandle'] === $sample['fieldHandle']);

    return $fromField === [] ?: json_encode(array_values($fromField));
});

check('EVENT_DEFINE_FIELD_LINKS can add links', function() use ($plugin, $entry, $run) {
    $handler = function(DefineFieldLinksEvent $event) use ($run) {
        $event->links[] = ['url' => "https://example.com/from-event-$run", 'linkText' => 'From event'];
    };
    Event::on(LinkExtractor::class, LinkExtractor::EVENT_DEFINE_FIELD_LINKS, $handler);

    try {
        $links = $plugin->linkExtractor->extractFromEntry($entry);
    } finally {
        Event::off(LinkExtractor::class, LinkExtractor::EVENT_DEFINE_FIELD_LINKS, $handler);
    }

    return in_array("https://example.com/from-event-$run", array_column($links, 'url'), true) ?: 'not added';
});

check('credentials are stripped from URLs', fn() => LinkUrl::stripCredentials('https://user:secret@example.com/a?b=1') === 'https://example.com/a?b=1' ?: LinkUrl::stripCredentials('https://user:secret@example.com/a?b=1'));

check('the CSV export honours the host filter', function() use ($plugin, $sample) {
    $csv = $plugin->reporting->generateCsv('broken', ['host' => $sample['host']]);
    $rows = array_slice(array_map('str_getcsv', array_filter(explode("\n", trim($csv)))), 1);
    $hosts = array_unique(array_map(fn($row) => parse_url($row[0], PHP_URL_HOST), $rows));

    return $rows !== [] && $hosts === [$sample['host']] ?: json_encode($hosts);
});

echo "\nNotifications\n";

// A listener that records what it's sent
$capture = sys_get_temp_dir() . "/appleseed-capture-$run.json";
$router = sys_get_temp_dir() . "/appleseed-router-$run.php";
file_put_contents($router, '<?php file_put_contents(' . var_export($capture, true) . ', json_encode(["headers" => getallheaders(), "body" => file_get_contents("php://input")]) . "\n", FILE_APPEND); http_response_code(200); echo "ok";');
$port = random_int(20000, 40000);
$cleanup['server'] = proc_open(['php', '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);

$lastScan = $plugin->reporting->getLastCompletedScan([ScanType::FULL, ScanType::DATABASE, ScanType::INCREMENTAL]);

check('the webhook is signed with the secret, and carries the scan and broken links', function() use ($plugin, $lastScan, $port, $capture) {
    @unlink($capture);
    $settings = $plugin->getSettings();
    $settings->webhookUrl = "http://127.0.0.1:$port/hook";
    $settings->webhookSecret = 'shh';

    $sent = $plugin->notifier->sendWebhook($lastScan, $settings);
    $request = json_decode(trim((string)@file_get_contents($capture)), true);
    $body = $request['body'] ?? '';
    $payload = json_decode($body, true);
    $signature = $request['headers']['X-Appleseed-Signature'] ?? '';

    return $sent && $signature === 'sha256=' . hash_hmac('sha256', $body, 'shh') && $payload['event'] === 'scan.completed' && $payload['scan']['id'] === $lastScan->id && isset($payload['brokenLinks'][0]['url'])
        ?: json_encode($request);
});

check('Slack gets a message listing broken links', function() use ($plugin, $lastScan, $port, $capture) {
    @unlink($capture);
    $settings = $plugin->getSettings();
    $settings->slackWebhookUrl = "http://127.0.0.1:$port/slack";

    $sent = $plugin->notifier->sendSlack($lastScan, $settings);
    $request = json_decode(trim((string)@file_get_contents($capture)), true);
    $text = json_decode($request['body'] ?? '', true)['text'] ?? '';

    return $sent && str_contains($text, 'Appleseed') && str_contains($text, '•') ?: $text;
});

check('“only newly broken” alerts on new breakages, not old ones', function() use ($plugin) {
    $settings = new Settings(['notifyOnlyNew' => true, 'notificationThreshold' => 1]);
    $old = new ScanRecord(['brokenCount' => 9, 'newBrokenCount' => 0, 'type' => ScanType::FULL]);
    $new = new ScanRecord(['brokenCount' => 9, 'newBrokenCount' => 2, 'type' => ScanType::FULL]);

    return !$plugin->notifier->shouldAlert($old, $settings) && $plugin->notifier->shouldAlert($new, $settings) ?: 'wrong';
});

check('a webhook that fails doesn’t log its URL', function() use ($plugin, $lastScan) {
    $settings = $plugin->getSettings();
    $settings->webhookUrl = 'http://127.0.0.1:1/secret-token-in-url';
    $logger = Craft::getLogger();
    $before = count($logger->messages);
    $sent = $plugin->notifier->sendWebhook($lastScan, $settings);
    $logged = implode("\n", array_map(fn($m) => is_string($m[0]) ? $m[0] : '', array_slice($logger->messages, $before)));

    return !$sent && !str_contains($logged, 'secret-token-in-url') ?: $logged;
});

echo "\nThe control panel\n";

check('a setting can name an environment variable that isn’t set here (saving used to throw)', function() {
    $settings = new Settings(['notificationEmails' => '$APPLESEED_NOT_SET_ANYWHERE', 'userAgent' => '$APPLESEED_NOT_SET_ANYWHERE', 'webhookUrl' => '$APPLESEED_NOT_SET_ANYWHERE']);

    return $settings->validate() && $settings->notificationEmails === '$APPLESEED_NOT_SET_ANYWHERE' && $settings->getNotificationEmailsArray() === [] && $settings->getUserAgentParsed() !== ''
        ?: json_encode($settings->getErrors());
});

check('settings reject a bad webhook URL and a made-up icon, and accept env vars', function() {
    $bad = new Settings(['webhookUrl' => 'not a url', 'navIcon' => 'no-such-icon-anywhere', 'navIconSvg' => '<script>alert(1)</script>']);
    $bad->validate();
    $good = new Settings(['webhookUrl' => '$APPLESEED_WEBHOOK_URL', 'slackWebhookUrl' => 'https://hooks.slack.com/services/x', 'navIcon' => 'link']);
    $good->validate();

    return $bad->hasErrors('webhookUrl') && $bad->hasErrors('navIcon') && $bad->hasErrors('navIconSvg') && !$good->hasErrors()
        ?: json_encode([$bad->getErrors(), $good->getErrors()]);
});

check('the sidebar label and icon come from settings, and a pasted SVG is sanitised', function() use ($plugin) {
    $item = $plugin->customiseNavItem(['label' => 'Appleseed', 'icon' => 'x'], new Settings(['navLabel' => 'Links', 'navIcon' => 'link']));
    $svg = $plugin->customiseNavItem(['label' => 'Appleseed', 'icon' => 'x'], new Settings(['navIconSvg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><path d="M0 0h1v1z"/></svg>']));
    $bad = $plugin->customiseNavItem(['label' => 'Appleseed', 'icon' => 'x'], new Settings(['navIcon' => '../../etc/passwd']));

    return $item['label'] === 'Links' && $item['icon'] === 'link'
        && str_contains($svg['icon'], '<path') && !str_contains($svg['icon'], 'script') && !str_contains($svg['icon'], 'onload')
        && $bad['icon'] === 'x'
        ?: json_encode([$item, $svg, $bad]);
});

check('the Broken Links widget renders for a viewer', function() use ($viewer) {
    Craft::$app->getUser()->setIdentity($viewer);

    try {
        $html = (new BrokenLinksWidget(['limit' => 3]))->getBodyHtml();
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }

    return str_contains((string)$html, 'appleseed-widget__counts') ?: substr((string)$html, 0, 300);
});

check('the entry editor shows the Appleseed panel', function() use ($entry) {
    [$admin] = client('admin', 'claudepassword');
    $path = parse_url($entry->getCpEditUrl(), PHP_URL_PATH);
    $response = $admin->get('index.php?p=' . ltrim((string)$path, '/'));
    $html = (string)$response->getBody();

    return str_contains($html, 'appleseed-entry-panel') && str_contains($html, 'appleseed-entry-scan-btn') ?: 'status ' . $response->getStatusCode();
});

check('the dashboard renders with the host filter and the “where to start” panels', function() {
    [$admin] = client('admin', 'claudepassword');
    $response = $admin->get('index.php?p=admin/appleseed/dashboard&status=broken');
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($html, 'appleseed-hotspots') && str_contains($html, 'data-type="incremental"') ?: 'status ' . $response->getStatusCode();
});

check('a viewer sees no scan buttons', function() use ($viewer, $password) {
    [$http] = client($viewer->username, $password);
    $html = (string)$http->get('index.php?p=admin/appleseed/dashboard')->getBody();

    return str_contains($html, 'appleseed-summary-cards') && !str_contains($html, 'appleseed-run-scan-btn') ?: 'buttons shown';
});

check('the new CP templates carry no inline styles or script handlers', function() {
    $found = [];
    foreach ([...glob(dirname(__DIR__, 2) . '/src/templates/_widgets/*/*.twig'), dirname(__DIR__, 2) . '/src/templates/_entry-sidebar.twig'] as $file) {
        if (preg_match('/\sstyle="|\son[a-z]+="/i', (string)file_get_contents($file))) {
            $found[] = basename($file);
        }
    }

    return $found === [] ?: implode(', ', $found);
});

@unlink($router);
@unlink($capture);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
