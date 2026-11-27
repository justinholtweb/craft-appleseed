<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Models;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;
use DOMDocument;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\ScanType;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Appleseed's settings.
 *
 * Every Appleseed 5.3 key is unchanged, so an upgraded site keeps what it had in project config,
 * and `config/craft/appleseed.php` overrides them as `config/appleseed.php` did. New in 6:
 * `notifyCpUsers` and `scanHistoryDays`.
 *
 * Text settings are nullable: Laravel turns a blank field into null before Craft assigns it.
 * `notificationEmails`, `userAgent`, `emailLayoutTemplate`, `slackWebhookUrl`, `webhookUrl` and
 * `webhookSecret` accept environment variables (`$APPLESEED_SLACK_WEBHOOK`); one that isn't set on
 * an environment leaves that feature off there.
 */
class Settings extends PluginSettings
{
    public const string FREQUENCY_MANUAL = 'manual';

    public const string FREQUENCY_DAILY = 'daily';

    public const string FREQUENCY_WEEKLY = 'weekly';

    public const string FREQUENCY_MONTHLY = 'monthly';

    /** Seconds between scheduled scans, per frequency. */
    public const array FREQUENCY_SECONDS = [
        self::FREQUENCY_DAILY => 86400,
        self::FREQUENCY_WEEKLY => 604800,
        self::FREQUENCY_MONTHLY => 2592000,
    ];

    public const string DEFAULT_USER_AGENT = 'Appleseed Link Checker (Craft CMS)';

    /** The largest pasted SVG icon accepted, in bytes. */
    public const int MAX_SVG_BYTES = 20000;

    // Link checking ---------------------------------------------------------------------------

    public bool $checkExternalLinks = true;

    /** HTTP timeout in seconds, 1–60. */
    public int $timeout = 10;

    /** Retries with exponential backoff after a connection error, 1–10. */
    public int $maxRetries = 3;

    /** Requests a second to any one host, 0.1–100. */
    public float $rateLimitPerSecond = 1.0;

    /** Links per queued checking job, 1–1000. */
    public int $scanBatchSize = 50;

    public ?string $userAgent = self::DEFAULT_USER_AGENT;

    // Spider ----------------------------------------------------------------------------------

    public bool $spiderEnabled = true;

    public int $maxPagesToSpider = 200;

    // Scheduling ------------------------------------------------------------------------------

    /** `manual`, `daily`, `weekly` or `monthly`. Scheduled scans start once a scan has completed. */
    public string $scanFrequency = self::FREQUENCY_MANUAL;

    /** Which scan the schedule runs: `full` or `incremental` (entries changed since the last scan). */
    public string $scheduledScanType = ScanType::FULL;

    public bool $scanOnEntrySave = false;

    // Notifications ---------------------------------------------------------------------------

    /** Comma-separated addresses, or an environment variable. */
    public ?string $notificationEmails = '';

    /** Minimum broken (or, with `notifyOnlyNew`, newly broken) links before anyone is told. */
    public int $notificationThreshold = 1;

    /** Notify only about links that broke since the scan before, rather than every broken link. */
    public bool $notifyOnlyNew = false;

    /** A Slack incoming-webhook URL (or environment variable). */
    public ?string $slackWebhookUrl = '';

    /** A URL (or environment variable) Appleseed POSTs a JSON summary to after every completed scan. */
    public ?string $webhookUrl = '';

    /** Signs webhook bodies as `X-Appleseed-Signature: sha256=…`. Environment variable recommended. */
    public ?string $webhookSecret = '';

    /** Optional site template wrapping the report email; it gets the report as `content`. */
    public ?string $emailLayoutTemplate = '';

    /** Also tell everyone who can run scans, in the control panel's notifications. New in 6. */
    public bool $notifyCpUsers = false;

    // Content ---------------------------------------------------------------------------------

    /** Newline-separated regex patterns; matching URLs are neither checked nor crawled. */
    public ?string $ignorePatterns = '';

    /** Field handles whose links are never collected, one per line or comma-separated. */
    public ?string $excludedFields = '';

    // Dashboard and control panel -------------------------------------------------------------

    /** The status the dashboard filters by on first load. Empty for all. */
    public ?string $defaultStatusFilter = '';

    /** The CP sidebar label. Empty uses "Appleseed". */
    public ?string $navLabel = null;

    /** The CP sidebar icon: a name from Craft's icon picker. */
    public ?string $navIcon = null;

    /** Raw SVG for the CP sidebar icon. Wins over `navIcon`. Sanitised every time it's drawn. */
    public ?string $navIconSvg = null;

    // Housekeeping ----------------------------------------------------------------------------

    /** Finished scans older than this many days are pruned (`model:prune`). Links are kept. New in 6. */
    public int $scanHistoryDays = 90;

    public function getRules(): array
    {
        return [
            'checkExternalLinks' => ['boolean'],
            'timeout' => ['integer', 'min:1', 'max:60'],
            'maxRetries' => ['integer', 'min:1', 'max:10'],
            'rateLimitPerSecond' => ['numeric', 'min:0.1', 'max:100'],
            'scanBatchSize' => ['integer', 'min:1', 'max:1000'],
            'userAgent' => ['nullable', 'string', 'max:255'],
            'spiderEnabled' => ['boolean'],
            'maxPagesToSpider' => ['integer', 'min:1', 'max:10000'],
            'scanFrequency' => ['in:'.implode(',', [self::FREQUENCY_MANUAL, self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY])],
            'scheduledScanType' => ['in:'.ScanType::FULL.','.ScanType::INCREMENTAL],
            'scanOnEntrySave' => ['boolean'],
            'notificationEmails' => ['nullable', 'string'],
            'notificationThreshold' => ['integer', 'min:1'],
            'notifyOnlyNew' => ['boolean'],
            'slackWebhookUrl' => ['nullable', 'string', 'max:500'],
            'webhookUrl' => ['nullable', 'string', 'max:500'],
            'webhookSecret' => ['nullable', 'string', 'max:500'],
            'emailLayoutTemplate' => ['nullable', 'string', 'max:500'],
            'notifyCpUsers' => ['boolean'],
            'ignorePatterns' => ['nullable', 'string'],
            'excludedFields' => ['nullable', 'string'],
            'defaultStatusFilter' => ['nullable', 'in:'.implode(',', LinkStatus::ALL)],
            'navLabel' => ['nullable', 'string', 'max:64'],
            'navIcon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+(:[a-z0-9\-]+)?$/'],
            'navIconSvg' => ['nullable', 'string', 'max:'.self::MAX_SVG_BYTES],
            'scanHistoryDays' => ['integer', 'min:1', 'max:3650'],
        ];
    }

    public function getMessages(): array
    {
        return [
            'navIcon.regex' => t('Choose an icon from the icon picker.', category: 'appleseed'),
        ];
    }

    /**
     * Normalises blank text to null, then makes the checks a rule string can't express: the icon
     * must exist, the SVG must be one `<svg>`, and webhook URLs must be http(s) or an environment
     * variable. A variable that isn't set here is fine; it's read again when a scan finishes.
     */
    #[Override]
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        foreach (['navLabel', 'navIcon', 'navIconSvg'] as $attribute) {
            $value = $this->$attribute;
            $this->$attribute = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        foreach (['slackWebhookUrl', 'webhookUrl', 'webhookSecret'] as $attribute) {
            $this->$attribute = trim((string) $this->$attribute);
        }

        parent::validate($attributeNames, $clearErrors, $throw);
        $errors = $this->errors();

        if ($this->navIcon !== null && ! $errors->has('navIcon') && ! self::iconExists($this->navIcon)) {
            $errors->add('navIcon', t('Choose an icon from the icon picker.', category: 'appleseed'));
        }

        if ($this->navIconSvg !== null && ! $errors->has('navIconSvg') && ! self::isSvgMarkup($this->navIconSvg)) {
            $errors->add('navIconSvg', t('Paste a single <svg> element.', category: 'appleseed'));
        }

        foreach (['slackWebhookUrl', 'webhookUrl'] as $attribute) {
            $url = (string) $this->$attribute;

            if ($url !== '' && ! $errors->has($attribute) && ! str_starts_with($url, '$') && ! self::isHttpUrl($url)) {
                $errors->add($attribute, t('Enter an http:// or https:// URL, or an environment variable.', category: 'appleseed'));
            }
        }

        foreach ($this->getIgnorePatternsArray() as $pattern) {
            if (@preg_match($pattern, '') === false) {
                $errors->add('ignorePatterns', t('“{pattern}” isn’t a valid regular expression.', ['pattern' => $pattern], category: 'appleseed'));
            }
        }

        return $errors->isEmpty();
    }

    // Parsed values -----------------------------------------------------------------------------

    public function getUserAgentParsed(): string
    {
        $userAgent = trim((string) Env::parse($this->userAgent));

        return $userAgent !== '' ? $userAgent : self::DEFAULT_USER_AGENT;
    }

    public function getEmailLayoutTemplateParsed(): string
    {
        return trim((string) Env::parse($this->emailLayoutTemplate));
    }

    public function getSlackWebhookUrlParsed(): string
    {
        $url = trim((string) Env::parse($this->slackWebhookUrl));

        return self::isHttpUrl($url) ? $url : '';
    }

    public function getWebhookUrlParsed(): string
    {
        $url = trim((string) Env::parse($this->webhookUrl));

        return self::isHttpUrl($url) ? $url : '';
    }

    public function getWebhookSecretParsed(): string
    {
        return (string) Env::parse($this->webhookSecret);
    }

    /**
     * Notification addresses with environment variables resolved; invalid and blank ones dropped.
     *
     * @return list<string>
     */
    public function getNotificationEmailsArray(): array
    {
        $parsed = (string) Env::parse($this->notificationEmails);
        $emails = array_map('trim', preg_split('/[\s,;]+/', $parsed) ?: []);

        return array_values(array_unique(array_filter(
            $emails,
            fn (string $email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /**
     * @return list<string> Field handles whose links aren't collected.
     */
    public function getExcludedFieldsArray(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', (string) $this->excludedFields) ?: []),
            fn (string $handle) => $handle !== '',
        ));
    }

    /**
     * @return list<string> The ignore patterns, one regex each.
     */
    public function getIgnorePatternsArray(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', (string) $this->ignorePatterns))),
            fn (string $line) => $line !== '',
        ));
    }

    /**
     * Whether a URL matches one of the ignore patterns. Invalid patterns are skipped.
     */
    public function matchesIgnorePattern(string $url): bool
    {
        foreach ($this->getIgnorePatternsArray() as $pattern) {
            try {
                if (@preg_match($pattern, $url) === 1) {
                    return true;
                }
            } catch (Throwable) {
                // Invalid regex: skip it
            }
        }

        return false;
    }

    /**
     * How often scheduled scans run, in seconds, or null for manual.
     */
    public function scheduleInterval(): ?int
    {
        return self::FREQUENCY_SECONDS[$this->scanFrequency] ?? null;
    }

    /**
     * The status the dashboard filters by when none was chosen.
     */
    public function defaultStatus(): string
    {
        return LinkStatus::normalizeFilter($this->defaultStatusFilter) ?? '';
    }

    // Icons -------------------------------------------------------------------------------------

    public static function iconExists(string $icon): bool
    {
        if (str_contains($icon, ':')) {
            // A custom icon set's `set:name`: checked when it's drawn.
            return true;
        }

        try {
            return is_file(Icons::resolveIconPath($icon));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the text is one `<svg>` element, optionally after an XML declaration, comments or a
     * doctype. Checked before the markup goes anywhere near `Html::svg()`, which would otherwise
     * treat a non-SVG string as a file path.
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (! preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $svg) || ! preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;

        try {
            // No network access, no entity expansion.
            $loaded = $doc->loadXML($svg, LIBXML_NONET);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }

    private static function isHttpUrl(string $url): bool
    {
        return $url !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
