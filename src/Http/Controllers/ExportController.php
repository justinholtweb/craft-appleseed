<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Http\Controllers;

use Illuminate\Http\Request;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Services\Reporting;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSV export, following the dashboard's filters. Without a status filter every link is
 * exported, ignored ones included.
 */
class ExportController
{
    public function __invoke(Request $request, Reporting $reporting, Settings $settings): Response
    {
        $filters = DashboardController::filters($request, $settings);

        // An export link always carries `status`; one without it means "everything".
        if (! $request->query->has('status')) {
            $filters['status'] = '';
        }

        return response($reporting->generateCsv($filters), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="appleseed-links-'.date('Y-m-d').'.csv"',
        ]);
    }
}
