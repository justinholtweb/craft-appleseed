<?php

namespace justinholtweb\appleseed\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\appleseed\models\Settings;
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

        $user = Craft::$app->getUser();

        return $this->renderTemplate('appleseed/settings/_index', [
            'settings' => $plugin->getSettings(),
            // Settings are project config. Holders of "Manage Appleseed settings" can read them;
            // only an admin, where admin changes are allowed, can change them.
            'readOnly' => !$user->getIsAdmin() || !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'notAdmin' => !$user->getIsAdmin(),
        ]);
    }

    /**
     * Save settings.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        // Saving writes project config — including which Twig template lays out the report email —
        // so it needs an admin, on an environment that allows admin changes. Until 5.2.5 the
        // "Manage Appleseed settings" permission alone was enough.
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $request = Craft::$app->getRequest();
        $settings->checkExternalLinks = (bool) $request->getBodyParam('checkExternalLinks');
        $settings->timeout = (int) $request->getBodyParam('timeout', 10);
        $settings->maxRetries = (int) $request->getBodyParam('maxRetries', 3);
        $settings->rateLimitPerSecond = (float) $request->getBodyParam('rateLimitPerSecond', 1.0);
        $settings->spiderEnabled = (bool) $request->getBodyParam('spiderEnabled');
        $settings->maxPagesToSpider = (int) $request->getBodyParam('maxPagesToSpider', 200);
        $settings->scanBatchSize = (int) $request->getBodyParam('scanBatchSize', 50);
        $settings->scanFrequency = $request->getBodyParam('scanFrequency', 'manual');
        $settings->scanOnEntrySave = (bool) $request->getBodyParam('scanOnEntrySave');
        $settings->notificationEmails = $request->getBodyParam('notificationEmails', '');
        $settings->notificationThreshold = (int) $request->getBodyParam('notificationThreshold', 1);
        $settings->ignorePatterns = $request->getBodyParam('ignorePatterns', '');
        $settings->userAgent = $request->getBodyParam('userAgent', 'Appleseed Link Checker (Craft CMS)');
        $settings->emailLayoutTemplate = $request->getBodyParam('emailLayoutTemplate', '');
        $settings->defaultStatusFilter = $request->getBodyParam('defaultStatusFilter', '');
        $settings->scheduledScanType = $request->getBodyParam('scheduledScanType', 'full');
        $settings->notifyOnlyNew = (bool) $request->getBodyParam('notifyOnlyNew');
        $settings->slackWebhookUrl = trim((string) $request->getBodyParam('slackWebhookUrl', ''));
        $settings->webhookUrl = trim((string) $request->getBodyParam('webhookUrl', ''));
        $settings->webhookSecret = trim((string) $request->getBodyParam('webhookSecret', ''));
        $settings->excludedFields = (string) $request->getBodyParam('excludedFields', '');

        // Blank means "use Appleseed's own", stored as null.
        foreach (['navLabel', 'navIcon', 'navIconSvg'] as $attribute) {
            $value = trim((string) $request->getBodyParam($attribute, ''));
            $settings->$attribute = $value !== '' ? $value : null;
        }

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('appleseed', 'Couldn’t save settings.'));
            return $this->renderTemplate('appleseed/settings/_index', [
                'settings' => $settings,
                'readOnly' => false,
                'notAdmin' => false,
            ]);
        }

        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        Craft::$app->getSession()->setNotice(Craft::t('appleseed', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
