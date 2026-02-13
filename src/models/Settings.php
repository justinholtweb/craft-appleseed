<?php

namespace justinholtweb\appleseed\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $checkExternalLinks = true;
    public int $timeout = 10;
    public int $maxRetries = 3;
    public float $rateLimitPerSecond = 1.0;
    public bool $spiderEnabled = true;
    public int $maxPagesToSpider = 200;
    public string $scanFrequency = 'weekly';
    public bool $scanOnEntrySave = false;
    public string $notificationEmails = '';
    public int $notificationThreshold = 1;
    public string $ignorePatterns = '';
    public string $userAgent = 'Appleseed Link Checker (Craft CMS)';

    protected function defineRules(): array
    {
        return [
            [['timeout', 'maxRetries', 'maxPagesToSpider', 'notificationThreshold'], 'integer', 'min' => 1],
            [['rateLimitPerSecond'], 'number', 'min' => 0.1, 'max' => 100],
            [['scanFrequency'], 'in', 'range' => ['manual', 'daily', 'weekly', 'monthly']],
            [['checkExternalLinks', 'spiderEnabled', 'scanOnEntrySave'], 'boolean'],
            [['notificationEmails', 'ignorePatterns', 'userAgent'], 'string'],
            [['timeout'], 'integer', 'max' => 60],
            [['maxRetries'], 'integer', 'max' => 10],
            [['maxPagesToSpider'], 'integer', 'max' => 10000],
        ];
    }

    /**
     * Get ignore patterns as an array of regex strings.
     */
    public function getIgnorePatternsArray(): array
    {
        if (empty($this->ignorePatterns)) {
            return [];
        }

        return array_filter(
            array_map('trim', explode("\n", $this->ignorePatterns)),
            fn(string $line) => $line !== '',
        );
    }

    /**
     * Get notification emails as an array.
     */
    public function getNotificationEmailsArray(): array
    {
        if (empty($this->notificationEmails)) {
            return [];
        }

        return array_filter(
            array_map('trim', explode(',', $this->notificationEmails)),
            fn(string $email) => $email !== '',
        );
    }
}
