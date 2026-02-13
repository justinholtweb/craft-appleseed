<?php

namespace justinholtweb\appleseed\console\controllers;

use craft\console\Controller;
use justinholtweb\appleseed\Plugin;
use yii\console\ExitCode;

/**
 * Check a single URL.
 */
class CheckUrlController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'index';

    /**
     * Check a single URL and report its status.
     *
     * Usage: craft appleseed/check-url https://example.com
     */
    public function actionIndex(string $url): int
    {
        $this->stdout("Checking: {$url}\n");

        $plugin = Plugin::getInstance();
        $result = $plugin->linkChecker->checkUrl($url);

        $this->stdout("\n");
        $this->stdout("  Status:      {$result->status}\n");

        if ($result->statusCode !== null) {
            $this->stdout("  HTTP Code:   {$result->statusCode}\n");
        }

        if ($result->redirectUrl) {
            $this->stdout("  Redirects to: {$result->redirectUrl}\n");
        }

        if ($result->redirectChain) {
            $this->stdout("  Redirect chain:\n");
            foreach ($result->redirectChain as $hop) {
                $this->stdout("    -> {$hop}\n");
            }
        }

        if ($result->errorMessage) {
            $this->stdout("  Error:       {$result->errorMessage}\n");
        }

        return $result->isBroken() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
