<?php

namespace justinholtweb\appleseed\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use justinholtweb\appleseed\helpers\ScanType;

class Settings extends Model
{
    /** The largest pasted SVG icon accepted, in bytes. */
    public const MAX_SVG_BYTES = 20000;

    public bool $checkExternalLinks = true;
    public int $timeout = 10;
    public int $maxRetries = 3;
    public float $rateLimitPerSecond = 1.0;
    public bool $spiderEnabled = true;
    public int $maxPagesToSpider = 200;
    public int $scanBatchSize = 50;
    public string $scanFrequency = 'manual';
    public bool $scanOnEntrySave = false;
    public ?string $notificationEmails = '';
    public int $notificationThreshold = 1;
    public string $ignorePatterns = '';
    public ?string $userAgent = 'Appleseed Link Checker (Craft CMS)';
    public ?string $emailLayoutTemplate = '';
    public string $defaultStatusFilter = '';

    /** Which scan the schedule runs: `full` or `incremental` (entries changed since the last scan). */
    public string $scheduledScanType = ScanType::FULL;

    /** Notify only about links that broke since the scan before, rather than every broken link. */
    public bool $notifyOnlyNew = false;

    /** A Slack incoming-webhook URL (or environment variable) to post scan results to. */
    public ?string $slackWebhookUrl = '';

    /** A URL (or environment variable) Appleseed POSTs a JSON summary to after every completed scan. */
    public ?string $webhookUrl = '';

    /** Optional secret (or environment variable) that signs webhook bodies as `X-Appleseed-Signature`. */
    public ?string $webhookSecret = '';

    /** Field handles whose links are never collected, one per line or comma-separated. */
    public string $excludedFields = '';

    /** The CP sidebar label. Empty uses "Appleseed". */
    public ?string $navLabel = null;

    /** The CP sidebar icon: a name from Craft's icon picker (e.g. `link`). */
    public ?string $navIcon = null;

    /** Raw SVG for the CP sidebar icon. Wins over `navIcon`. Stored as entered, sanitised every time it's drawn. */
    public ?string $navIconSvg = null;

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'notificationEmails',
                    'userAgent',
                    'emailLayoutTemplate',
                    'slackWebhookUrl',
                    'webhookUrl',
                    'webhookSecret',
                ],
            ],
        ];
    }

    /*
     * The env-parsed settings are nullable: during validation Craft's env parser swaps in each
     * variable's value, and an undefined variable comes back null. As plain strings, a reference
     * to a variable that's only set in production made saving the settings throw.
     */

    public function getNotificationEmailsParsed(): string
    {
        return (string)App::parseEnv((string)$this->notificationEmails);
    }

    public function getUserAgentParsed(): string
    {
        $userAgent = trim((string)App::parseEnv((string)$this->userAgent));

        return $userAgent !== '' ? $userAgent : 'Appleseed Link Checker (Craft CMS)';
    }

    public function getEmailLayoutTemplateParsed(): string
    {
        return (string)App::parseEnv((string)$this->emailLayoutTemplate);
    }

    public function getSlackWebhookUrlParsed(): string
    {
        return trim((string)App::parseEnv((string)$this->slackWebhookUrl));
    }

    public function getWebhookUrlParsed(): string
    {
        return trim((string)App::parseEnv((string)$this->webhookUrl));
    }

    public function getWebhookSecretParsed(): string
    {
        return (string)App::parseEnv((string)$this->webhookSecret);
    }

    /**
     * @return string[] Field handles whose links aren't collected.
     */
    public function getExcludedFieldsArray(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', $this->excludedFields) ?: []),
            fn(string $handle) => $handle !== '',
        ));
    }

    /**
     * The status options offered by the dashboard filter, and the allowed values for
     * {@see $defaultStatusFilter}. An empty value means no filtering.
     *
     * @return array<array{label: string, value: string}>
     */
    public function getStatusFilterOptions(): array
    {
        return [
            ['label' => Craft::t('appleseed', 'All Statuses'), 'value' => ''],
            ['label' => Craft::t('appleseed', 'Broken'), 'value' => 'broken'],
            ['label' => Craft::t('appleseed', 'Redirect'), 'value' => 'redirect'],
            ['label' => Craft::t('appleseed', 'Working'), 'value' => 'working'],
            ['label' => Craft::t('appleseed', 'Server Error'), 'value' => 'server_error'],
            ['label' => Craft::t('appleseed', 'Timeout'), 'value' => 'timeout'],
            ['label' => Craft::t('appleseed', 'DNS Error'), 'value' => 'dns_error'],
            ['label' => Craft::t('appleseed', 'Blocked'), 'value' => 'blocked'],
            ['label' => Craft::t('appleseed', 'Not checked'), 'value' => 'unknown'],
            ['label' => Craft::t('appleseed', 'Ignored'), 'value' => 'ignored'],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['timeout', 'maxRetries', 'maxPagesToSpider', 'notificationThreshold', 'scanBatchSize'], 'integer', 'min' => 1],
            [['rateLimitPerSecond'], 'number', 'min' => 0.1, 'max' => 100],
            [['scanFrequency'], 'in', 'range' => ['manual', 'daily', 'weekly', 'monthly']],
            [['checkExternalLinks', 'spiderEnabled', 'scanOnEntrySave', 'notifyOnlyNew'], 'boolean'],
            [['notificationEmails', 'ignorePatterns', 'userAgent', 'emailLayoutTemplate', 'slackWebhookUrl', 'webhookUrl', 'webhookSecret', 'excludedFields'], 'string'],
            [['scheduledScanType'], 'in', 'range' => [ScanType::FULL, ScanType::INCREMENTAL]],
            [['slackWebhookUrl', 'webhookUrl'], 'validateWebhookUrl', 'skipOnEmpty' => true],
            [['navLabel'], 'string', 'max' => 64],
            [['navIcon'], 'validateNavIcon', 'skipOnEmpty' => true],
            [['navIconSvg'], 'string', 'max' => self::MAX_SVG_BYTES],
            [['navIconSvg'], 'validateNavIconSvg', 'skipOnEmpty' => true],
            [['defaultStatusFilter'], 'in', 'range' => array_column($this->getStatusFilterOptions(), 'value')],
            [['timeout'], 'integer', 'max' => 60],
            [['maxRetries'], 'integer', 'max' => 10],
            [['maxPagesToSpider'], 'integer', 'max' => 10000],
            [['scanBatchSize'], 'integer', 'max' => 1000],
        ];
    }

    /**
     * Get ignore patterns as an array of regex strings.
     */
    public function getIgnorePatternsArray(): array
    {
        if ($this->ignorePatterns === '') {
            return [];
        }

        return array_filter(
            array_map('trim', explode("\n", $this->ignorePatterns)),
            fn(string $line) => $line !== '',
        );
    }

    /**
     * Whether a URL matches one of the ignore patterns. Invalid patterns are skipped.
     */
    public function matchesIgnorePattern(string $url): bool
    {
        foreach ($this->getIgnorePatternsArray() as $pattern) {
            try {
                if (@preg_match($pattern, $url)) {
                    return true;
                }
            } catch (\Throwable) {
                // Invalid regex, skip
            }
        }

        return false;
    }

    /**
     * Get notification emails as an array.
     */
    public function getNotificationEmailsArray(): array
    {
        $parsed = $this->getNotificationEmailsParsed();

        if ($parsed === '') {
            return [];
        }

        return array_filter(
            array_map('trim', explode(',', $parsed)),
            fn(string $email) => $email !== '',
        );
    }

    public function validateWebhookUrl(string $attribute): void
    {
        // Runs with the env parser's value swapped in: null or '' for a variable that isn't set
        // here, which is fine — it's read again when a scan finishes.
        $url = trim((string)$this->$attribute);

        if ($url === '' || str_starts_with($url, '$')) {
            return;
        }

        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
            $this->addError($attribute, Craft::t('appleseed', 'Enter an http:// or https:// URL, or an environment variable.'));
        }
    }

    public function validateNavIcon(string $attribute): void
    {
        if (!self::iconExists((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('appleseed', 'Choose an icon from the icon picker.'));
        }
    }

    public function validateNavIconSvg(string $attribute): void
    {
        if (!self::isSvgMarkup((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('appleseed', 'Paste a single <svg> element.'));
        }
    }

    /**
     * Whether an icon name is one of Craft's bundled icons (the set the icon picker offers).
     */
    public static function iconExists(string $name): bool
    {
        if ($name === '' || strlen($name) > 64 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            return false;
        }

        // `@appicons` is Craft's solid set on a real install.
        foreach (["@appicons/$name.svg", "@app/icons/solid/$name.svg", "@app/icons/custom-icons/$name.svg"] as $alias) {
            $path = Craft::getAlias($alias, false);

            if (is_string($path) && is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the text is one `<svg>` element, optionally after an XML declaration, comments or a
     * doctype. Checked before the markup goes anywhere near `Html::svg()`, which would otherwise
     * treat a non-SVG string as a file path.
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (!preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $svg)) {
            return false;
        }

        if (!preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // No network access, no entity expansion.
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }
}
