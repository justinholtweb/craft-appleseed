<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Http\Controllers;

use JustinHoltWeb\Appleseed\Services\Reporting;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;

/**
 * The report email for the last completed scan, in the browser.
 */
class EmailPreviewController
{
    public function __invoke(Reporting $reporting): Response
    {
        $scan = $reporting->getLastCompletedScan();

        if ($scan === null) {
            return redirect(cp_url('appleseed/dashboard'));
        }

        return response($reporting->renderScanReportEmail($scan), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
