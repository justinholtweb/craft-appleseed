<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Jobs;

use CraftCms\Cms\Queue\Job;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Appleseed\Models\Settings;
use Override;

use function CraftCms\Cms\t;

/**
 * POSTs a completed scan's summary to the webhook URL in Appleseed's settings, with
 * `X-Appleseed-Event: scan.completed` and, when a secret is set,
 * `X-Appleseed-Signature: sha256=<hex HMAC-SHA256 of the body>`. Retried with backoff.
 */
class SendScanWebhook extends Job
{
    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {
        parent::__construct();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Settings $settings): void
    {
        $url = $settings->getWebhookUrlParsed();

        if ($url === '') {
            return;
        }

        $body = (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'User-Agent' => 'Appleseed-Webhook/1',
            'X-Appleseed-Event' => 'scan.completed',
        ];

        if (($secret = $settings->getWebhookSecretParsed()) !== '') {
            $headers['X-Appleseed-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        Http::timeout(15)->connectTimeout(5)->withHeaders($headers)->withBody($body, 'application/json')->post($url)->throw();
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('Sending Appleseed’s scan webhook', category: 'appleseed');
    }
}
