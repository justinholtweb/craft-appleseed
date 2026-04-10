<?php

namespace justinholtweb\appleseed\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

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
    public string $emailLayoutTemplate = '';

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'notificationEmails',
                    'userAgent',
                    'emailLayoutTemplate',
                ],
            ],
        ];
    }

    public function getNotificationEmailsParsed(): string
    {
        return App::parseEnv($this->notificationEmails);
    }

    public function getUserAgentParsed(): string
    {
        return App::parseEnv($this->userAgent);
    }

    public function getEmailLayoutTemplateParsed(): string
    {
        return App::parseEnv($this->emailLayoutTemplate);
    }

    protected function defineRules(): array
    {
        return [
            [['timeout', 'maxRetries', 'maxPagesToSpider', 'notificationThreshold'], 'integer', 'min' => 1],
            [['rateLimitPerSecond'], 'number', 'min' => 0.1, 'max' => 100],
            [['scanFrequency'], 'in', 'range' => ['manual', 'daily', 'weekly', 'monthly']],
            [['checkExternalLinks', 'spiderEnabled', 'scanOnEntrySave'], 'boolean'],
            [['notificationEmails', 'ignorePatterns', 'userAgent', 'emailLayoutTemplate'], 'string'],
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
        if ($this->ignorePatterns === '') {
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
        $parsed = $this->getNotificationEmailsParsed();

        if ($parsed === '') {
            return [];
        }

        return array_filter(
            array_map('trim', explode(',', $parsed)),
            fn(string $email) => $email !== '',
        );
    }
}
