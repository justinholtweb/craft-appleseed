<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Http\Controllers;

use CraftCms\Cms\Entry\Elements\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\Presenter;
use JustinHoltWeb\Appleseed\Support\ScanType;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Starting, following and cancelling scans (JSON, for the dashboard and the entry sidebar).
 */
class ScansController
{
    /**
     * Queues a scan: `type` (full, database, incremental or recheck), optionally `sectionIds`.
     * Never run inline: a full scan comfortably outlives a request.
     */
    public function store(Request $request, Scanner $scanner): JsonResponse
    {
        $type = (string) $request->input('type', ScanType::FULL);
        $sectionIds = array_values(array_filter(array_map('intval', (array) $request->input('sectionIds', []))));

        if (! in_array($type, ScanType::STARTABLE, true)) {
            return response()->json(['success' => false, 'message' => t('Unknown scan type.', category: 'appleseed')], 400);
        }

        if (! $scanner->queueScan($type, $sectionIds !== [] ? $sectionIds : null)) {
            return response()->json(['success' => false, 'message' => t('A scan is already running.', category: 'appleseed')], 409);
        }

        return response()->json(['success' => true, 'message' => t('Scan queued.', category: 'appleseed')]);
    }

    public function cancel(Scanner $scanner): JsonResponse
    {
        $cancelled = $scanner->cancelRunningScans();

        return response()->json([
            'success' => true,
            'cancelled' => $cancelled,
            'message' => $cancelled > 0 ? t('Scan cancelled.', category: 'appleseed') : t('No scan was running.', category: 'appleseed'),
        ]);
    }

    /**
     * Queues a scan of one entry, from the entry editor's sidebar.
     */
    public function entry(Request $request, Scanner $scanner): JsonResponse
    {
        $entryId = $request->integer('entryId');
        $siteId = $request->integer('siteId') ?: null;
        $entry = $entryId > 0 ? Entry::find()->id($entryId)->siteId($siteId ?? '*')->status(null)->one() : null;
        $user = currentUser();

        if (! $entry instanceof Entry || $user === null || ! $user->can('view', $entry)) {
            abort(404, t('Entry not found.', category: 'appleseed'));
        }

        $scanner->queueEntryScan($entryId, $siteId);

        return response()->json(['success' => true, 'message' => t('Entry scan queued. Reload the entry once the queue has run it.', category: 'appleseed')]);
    }

    /**
     * The running scan's progress, for the dashboard to poll. While the scan's links are being
     * checked as a batch, the batch's progress; before that, the links checked so far.
     */
    public function progress(Reporting $reporting, Scanner $scanner): JsonResponse
    {
        $running = $reporting->getRunningScan();

        if ($running === null) {
            $queued = $scanner->isQueued();
            $last = $reporting->getLastCompletedScan();

            return response()->json([
                'running' => $queued,
                'finished' => ! $queued,
                'progress' => 0,
                'label' => $queued ? t('Waiting for the queue…', category: 'appleseed') : null,
                'lastScan' => $last !== null ? Presenter::scan($last) : null,
            ]);
        }

        $batch = $scanner->batchFor($running->id);
        $percent = $batch !== null ? $batch->progress() : $running->progressPercent();

        return response()->json([
            'running' => true,
            'finished' => false,
            'progress' => $percent,
            'totalLinksFound' => $running->totalLinksFound,
            'totalLinksChecked' => $running->totalLinksChecked,
            'label' => $running->totalLinksFound > 0
                ? t('Checked {checked} of {total} links ({percent}%)', ['checked' => $running->totalLinksChecked, 'total' => $running->totalLinksFound, 'percent' => $percent], category: 'appleseed')
                : t('Discovering links…', category: 'appleseed'),
        ]);
    }
}
