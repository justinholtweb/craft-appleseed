<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use GuzzleHttp\RequestOptions;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\helpers\Presenter;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\ScanRecord;
use yii\db\Query;

/**
 * Tells people about a finished scan: email, Slack, and a JSON webhook.
 *
 * Scans of a single entry (after it's saved) never notify — an email for every save that
 * touched a broken link is noise.
 *
 * @since 5.3.0
 */
class Notifier extends Component
{
    /** Broken links listed in a Slack message. */
    private const SLACK_LINK_LIMIT = 10;

    /** Broken links included in a webhook body. */
    private const WEBHOOK_LINK_LIMIT = 100;

    public function scanCompleted(ScanRecord $scan): void
    {
        if ($scan->type === ScanType::ENTRY) {
            return;
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if ($this->shouldAlert($scan, $settings)) {
            Plugin::getInstance()->reporting->sendScanNotification($scan);
            $this->sendSlack($scan, $settings);
        }

        $this->sendWebhook($scan, $settings);
    }

    /**
     * Whether a scan's results cross the notification threshold: broken links, or with "only new
     * breakages" turned on, links that broke during this scan.
     */
    public function shouldAlert(ScanRecord $scan, Settings $settings): bool
    {
        $count = $settings->notifyOnlyNew ? $scan->newBrokenCount : $scan->brokenCount;

        return $count > 0 && $count >= max(1, $settings->notificationThreshold);
    }

    public function sendSlack(ScanRecord $scan, Settings $settings): bool
    {
        $url = $settings->getSlackWebhookUrlParsed();
        if ($url === '') {
            return false;
        }

        $dashboardUrl = UrlHelper::cpUrl('appleseed/dashboard', ['status' => 'broken']);
        $lines = [
            $settings->notifyOnlyNew
                ? Craft::t('appleseed', '*Appleseed:* {count, plural, =1{# link} other{# links}} broke since the last scan ({total} broken in all).', ['count' => $scan->newBrokenCount, 'total' => $scan->brokenCount])
                : Craft::t('appleseed', '*Appleseed:* {count, plural, =1{# broken link} other{# broken links}} found.', ['count' => $scan->brokenCount]),
        ];

        foreach ($this->_brokenLinks($scan, self::SLACK_LINK_LIMIT, $settings->notifyOnlyNew) as $link) {
            $code = $link['statusCode'] ? " ({$link['statusCode']})" : '';
            $lines[] = '• <' . Presenter::linkCpUrl((int)$link['id']) . '|' . $this->_slackEscape($link['url']) . '>' . $code;
        }

        $lines[] = '<' . $dashboardUrl . '|' . Craft::t('appleseed', 'Open the dashboard') . '>';

        return $this->_post($url, ['text' => implode("\n", $lines)], [], 'Slack');
    }

    /**
     * POSTs a JSON summary of a completed scan to the webhook URL, signed with the secret when
     * there is one: `X-Appleseed-Signature: sha256=<hex HMAC of the body>`.
     */
    public function sendWebhook(ScanRecord $scan, Settings $settings): bool
    {
        $url = $settings->getWebhookUrlParsed();
        if ($url === '') {
            return false;
        }

        $reporting = Plugin::getInstance()->reporting;
        $payload = [
            'event' => 'scan.completed',
            'site' => Craft::$app->getSystemName(),
            'dashboardUrl' => UrlHelper::cpUrl('appleseed/dashboard'),
            'scan' => Presenter::scan($scan),
            'summary' => $reporting->getDashboardSummary(),
            'brokenLinks' => array_map(
                fn(array $link) => Presenter::link($link, false) + ['cpUrl' => Presenter::linkCpUrl((int)$link['id'])],
                $this->_brokenLinks($scan, self::WEBHOOK_LINK_LIMIT, false),
            ),
        ];

        $body = Json::encode($payload);
        $headers = ['X-Appleseed-Event' => 'scan.completed'];
        $secret = $settings->getWebhookSecretParsed();
        if ($secret !== '') {
            $headers['X-Appleseed-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        return $this->_post($url, $body, $headers, 'webhook');
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function _brokenLinks(ScanRecord $scan, int $limit, bool $onlyNew): array
    {
        $query = (new Query())
            ->from('{{%appleseed_links}}')
            ->where(['status' => LinkStatus::BROKEN_STATUSES, 'isIgnored' => false])
            ->orderBy(['firstBrokenAt' => SORT_DESC, 'id' => SORT_ASC])
            ->limit($limit);

        if ($onlyNew && $scan->startedAt) {
            $query->andWhere(['>=', 'firstBrokenAt', $scan->startedAt]);
        }

        return $query->all();
    }

    private function _slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '%7C'], $text);
    }

    /**
     * @param array<string, mixed>|string $body An array is sent as JSON; a string is sent as-is.
     * @param array<string, string> $headers
     */
    private function _post(string $url, array|string $body, array $headers, string $what): bool
    {
        try {
            $client = Craft::createGuzzleClient([
                RequestOptions::TIMEOUT => 10,
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::HTTP_ERRORS => false,
            ]);

            $options = [RequestOptions::HEADERS => $headers + ['Content-Type' => 'application/json']];
            $options[is_array($body) ? RequestOptions::JSON : RequestOptions::BODY] = $body;

            $response = $client->post($url, $options);

            if ($response->getStatusCode() >= 300) {
                Craft::warning("Appleseed: the {$what} answered {$response->getStatusCode()}.", __METHOD__);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // The URL can hold a secret (Slack's always does), and Guzzle puts it in its messages.
            $message = str_replace($url, '[url]', $e->getMessage());
            Craft::warning("Appleseed: couldn't send the {$what}: {$message}", __METHOD__);
            return false;
        }
    }
}
