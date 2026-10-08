<?php
/**
 * What Appleseed will fetch, who can change its settings, and the control panel's styling —
 * checked in the plugin-testing harness, partly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-appleseed/tests/integration/security.php
 *
 * Until 5.2.5: the link checker requested any URL in content, cloud metadata and intranet hosts
 * included, and reported what came back; "Manage Appleseed settings" could save settings — project
 * config, including the email layout template — without being an admin; and the dashboard's styles
 * hard-coded light colours.
 *
 * Restores Appleseed's settings in a fresh process (see craft-nuke's tests/README). Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\appleseed\helpers\UrlGuard;
use justinholtweb\appleseed\helpers\UrlRefusedException;
use justinholtweb\appleseed\Plugin;

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

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$password = 'Appleseed-' . bin2hex(random_bytes(6));
$settingsPath = 'plugins.appleseed.settings';
$settingsBefore = Craft::$app->getProjectConfig()->get($settingsPath);
$cleanup = ['users' => []];

register_shutdown_function(function() use (&$cleanup, $settingsPath, $settingsBefore, $root) {
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    $restore = sys_get_temp_dir() . '/appleseed-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$pc = Craft::$app->getProjectConfig();
$pc->set(' . var_export($settingsPath, true) . ', ' . var_export($settingsBefore, true) . ', "Restore Appleseed settings after security.php");
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not restore Appleseed's settings: " . implode("\n", $out) . "\n");
});

echo "\nWhat the link checker will fetch\n";

$internal = [
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'loopback' => 'http://127.0.0.1:6379/',
    'private 10/8' => 'http://10.0.0.5/admin',
    'private 192.168/16' => 'http://192.168.1.1/',
    'carrier-grade NAT' => 'http://100.64.0.1/',
    'IPv6 loopback' => 'http://[::1]/',
    'IPv6 unique local' => 'http://[fd00::1]/',
    'IPv4-mapped IPv6' => 'http://[::ffff:127.0.0.1]/',
    'a name for loopback' => 'http://localhost/',
    'not http' => 'file:///etc/passwd',
];

foreach ($internal as $label => $url) {
    check("refuses $label", fn() => UrlGuard::refusal($url) !== null ?: 'allowed');
}

check('allows a public address', fn() => UrlGuard::refusal('https://93.184.216.34/') === null ?: UrlGuard::refusal('https://93.184.216.34/'));

check('allows the site’s own host, even though it resolves to a private address here', function() {
    $host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);

    return UrlGuard::refusal("https://$host/some/page") === null ?: "refused $host";
});

check('a scan doesn’t request an internal link — it records it as not checked', function() use ($plugin) {
    $started = microtime(true);
    $result = $plugin->linkChecker->checkUrl('http://169.254.169.254/latest/meta-data/');

    return $result->status === 'ignored' && str_contains((string)$result->errorMessage, 'private or internal') && microtime(true) - $started < 1
        ?: "status {$result->status}: {$result->errorMessage}";
});

check('a redirect to an internal address is stopped at the hop', function() {
    try {
        UrlGuard::onRedirect(null, null, 'http://169.254.169.254/latest/meta-data/');
    } catch (UrlRefusedException) {
        return true;
    }

    return 'the redirect was allowed';
});

check('…and the link checker’s client is set up to do that', function() use ($plugin) {
    $method = new ReflectionMethod($plugin->linkChecker, '_getClient');
    $client = $method->invoke($plugin->linkChecker, $plugin->getSettings());
    $redirects = $client->getConfig('allow_redirects');

    return ($redirects['on_redirect'] ?? null) === [UrlGuard::class, 'onRedirect'] ?: json_encode($redirects);
});

echo "\nSettings\n";

$user = static function(string $name, array $permissions) use (&$cleanup, $run, $password): User {
    $u = new User(['username' => "appleseed-$name-$run", 'email' => "appleseed-$name-$run@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($u, false);
    Craft::$app->getUsers()->activateUser($u);
    Craft::$app->getUserPermissions()->saveUserPermissions($u->id, $permissions);
    $cleanup['users'][] = $u;

    return $u;
};

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return [$http, $csrf];
}

$manager = $user('settings', ['accesscp', 'accessplugin-appleseed', 'appleseed-managesettings']);
$viewer = $user('viewer', ['accesscp', 'accessplugin-appleseed', 'appleseed-viewdashboard']);
[$managerHttp, $managerCsrf] = client($manager->username, $password);

check('a console save keeps Appleseed’s permissions (they used to be registered for CP requests only)', function() use ($manager) {
    $saved = Craft::$app->getUserPermissions()->getPermissionsByUserId($manager->id);

    return in_array('appleseed-managesettings', $saved, true) ?: json_encode($saved);
});

check('“Manage Appleseed settings” sees them read-only', function() use ($managerHttp) {
    $html = (string)$managerHttp->get('index.php?p=admin/appleseed/settings')->getBody();

    return str_contains($html, 'Only an admin can change these settings.') && !str_contains($html, 'appleseed/settings/save') ?: 'editable';
});

check('…and can’t save them, email layout template included', function() use ($managerHttp, $managerCsrf, $plugin, $run) {
    $before = $plugin->getSettings()->emailLayoutTemplate;
    $status = $managerHttp->post('index.php?p=admin/actions/appleseed/settings/save', [
        'form_params' => ['emailLayoutTemplate' => "_evil/$run", 'CRAFT_CSRF_TOKEN' => $managerCsrf()],
    ])->getStatusCode();
    $stored = Craft::$app->getProjectConfig()->get('plugins.appleseed.settings.emailLayoutTemplate', true);

    return $status === 403 && $stored !== "_evil/$run" ?: "status $status, stored " . var_export($stored, true) . " (was $before)";
});

check('an admin can still save them', function() use ($plugin) {
    [$admin, $csrf] = client('admin', 'claudepassword');
    $settings = $plugin->getSettings();
    $status = $admin->post('index.php?p=admin/actions/appleseed/settings/save', ['form_params' => [
        'checkExternalLinks' => $settings->checkExternalLinks ? '1' : '', 'timeout' => $settings->timeout, 'maxRetries' => $settings->maxRetries,
        'rateLimitPerSecond' => $settings->rateLimitPerSecond, 'spiderEnabled' => $settings->spiderEnabled ? '1' : '',
        'maxPagesToSpider' => $settings->maxPagesToSpider, 'scanBatchSize' => $settings->scanBatchSize, 'scanFrequency' => $settings->scanFrequency,
        'scanOnEntrySave' => $settings->scanOnEntrySave ? '1' : '', 'notificationEmails' => $settings->notificationEmails,
        'notificationThreshold' => $settings->notificationThreshold, 'ignorePatterns' => $settings->ignorePatterns,
        'userAgent' => $settings->userAgent, 'emailLayoutTemplate' => $settings->emailLayoutTemplate,
        'defaultStatusFilter' => $settings->defaultStatusFilter, 'CRAFT_CSRF_TOKEN' => $csrf(),
    ]])->getStatusCode();

    return in_array($status, [200, 302], true) ?: "status $status";
});

check('the subnav only offers the screens each user can open', function() use ($plugin, $manager, $viewer) {
    $nav = static function(User $u) use ($plugin): array {
        Craft::$app->getUser()->setIdentity(User::find()->id($u->id)->status(null)->one());

        return array_keys($plugin->getCpNavItem()['subnav'] ?? []);
    };
    $managerNav = $nav($manager);
    $viewerNav = $nav($viewer);
    Craft::$app->getUser()->setIdentity(null);

    return $managerNav === ['settings'] && $viewerNav === ['dashboard'] ?: json_encode([$managerNav, $viewerNav]);
});

echo "\nThe control panel\n";

check('the stylesheet uses Craft’s colours, not its own', function() {
    $css = file_get_contents(dirname(__DIR__, 2) . '/src/assets/dist/css/appleseed.css');

    return preg_match_all('/#[0-9a-f]{3,6}\b/i', $css, $m) === 0 ?: implode(', ', array_unique($m[0]));
});

check('the CP templates carry no inline styles or inline script handlers', function() {
    $found = [];
    foreach (glob(dirname(__DIR__, 2) . '/src/templates/{dashboard,settings}/*.twig', GLOB_BRACE) as $file) {
        $twig = file_get_contents($file);
        if (preg_match('/\sstyle="|\son[a-z]+="/i', $twig)) {
            $found[] = basename(dirname($file)) . '/' . basename($file);
        }
    }

    return $found === [] ?: implode(', ', $found);
});

check('the dashboard renders, with Craft’s own select for the status filter', function() {
    [$admin] = client('admin', 'claudepassword');
    $response = $admin->get('index.php?p=admin/appleseed/dashboard');
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($html, 'id="appleseed-status-filter"') ?: 'status ' . $response->getStatusCode();
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
