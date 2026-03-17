<?php

namespace justinholtweb\appleseed\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\appleseed\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('appleseed-manageSettings');

        return true;
    }

    /**
     * Settings page.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('appleseed/settings/_index', [
            'settings' => $plugin->getSettings(),
        ]);
    }

    /**
     * Save settings.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $request = Craft::$app->getRequest();
        $settings->checkExternalLinks = (bool) $request->getBodyParam('checkExternalLinks');
        $settings->timeout = (int) $request->getBodyParam('timeout', 10);
        $settings->maxRetries = (int) $request->getBodyParam('maxRetries', 3);
        $settings->rateLimitPerSecond = (float) $request->getBodyParam('rateLimitPerSecond', 1.0);
        $settings->spiderEnabled = (bool) $request->getBodyParam('spiderEnabled');
        $settings->maxPagesToSpider = (int) $request->getBodyParam('maxPagesToSpider', 200);
        $settings->scanFrequency = $request->getBodyParam('scanFrequency', 'weekly');
        $settings->scanOnEntrySave = (bool) $request->getBodyParam('scanOnEntrySave');
        $settings->notificationEmails = $request->getBodyParam('notificationEmails', '');
        $settings->notificationThreshold = (int) $request->getBodyParam('notificationThreshold', 1);
        $settings->ignorePatterns = $request->getBodyParam('ignorePatterns', '');
        $settings->userAgent = $request->getBodyParam('userAgent', 'Appleseed Link Checker (Craft CMS)');
        $settings->emailLayoutTemplate = $request->getBodyParam('emailLayoutTemplate', '');

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError('Couldn\'t save settings.');
            return $this->renderTemplate('appleseed/settings/_index', [
                'settings' => $settings,
            ]);
        }

        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        Craft::$app->getSession()->setNotice('Settings saved.');

        return $this->redirectToPostedUrl();
    }
}
