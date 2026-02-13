<?php

namespace justinholtweb\appleseed\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\appleseed\jobs\CheckLinksJob;
use justinholtweb\appleseed\jobs\ScanJob;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\ScanRecord;
use yii\web\Response;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('appleseed-viewDashboard');

        return true;
    }

    /**
     * Main dashboard page.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $summary = $plugin->reporting->getDashboardSummary();
        $lastScan = $plugin->reporting->getLastCompletedScan();
        $runningScan = $plugin->reporting->getRunningScan();

        $statusFilter = Craft::$app->getRequest()->getQueryParam('status');
        $search = Craft::$app->getRequest()->getQueryParam('search');
        $page = (int) (Craft::$app->getRequest()->getQueryParam('page', 1));
        $sort = Craft::$app->getRequest()->getQueryParam('sort', 'status');
        $direction = Craft::$app->getRequest()->getQueryParam('direction', 'asc');

        $results = $plugin->reporting->getLinkResults($statusFilter, $search, $page, 50, $sort, $direction);

        return $this->renderTemplate('appleseed/dashboard/_index', [
            'summary' => $summary,
            'results' => $results,
            'lastScan' => $lastScan,
            'runningScan' => $runningScan,
            'statusFilter' => $statusFilter,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
        ]);
    }

    /**
     * Link detail page.
     */
    public function actionDetail(int $linkId): Response
    {
        $plugin = Plugin::getInstance();
        $link = $plugin->reporting->getLinkDetail($linkId);

        if (!$link) {
            throw new \yii\web\NotFoundHttpException('Link not found');
        }

        return $this->renderTemplate('appleseed/dashboard/_detail', [
            'link' => $link,
        ]);
    }

    /**
     * Start a full scan (AJAX).
     */
    public function actionRunScan(): Response
    {
        $this->requirePermission('appleseed-runScans');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Craft::$app->getQueue()->push(new ScanJob());

        return $this->asJson(['success' => true, 'message' => 'Scan queued']);
    }

    /**
     * Get scan progress (AJAX polling).
     */
    public function actionScanProgress(): Response
    {
        $this->requireAcceptsJson();

        $runningScan = Plugin::getInstance()->reporting->getRunningScan();

        if (!$runningScan) {
            $lastScan = Plugin::getInstance()->reporting->getLastCompletedScan();
            return $this->asJson([
                'running' => false,
                'lastScan' => $lastScan ? [
                    'id' => $lastScan->id,
                    'status' => $lastScan->status,
                    'totalLinksFound' => $lastScan->totalLinksFound,
                    'totalLinksChecked' => $lastScan->totalLinksChecked,
                    'brokenCount' => $lastScan->brokenCount,
                    'redirectCount' => $lastScan->redirectCount,
                    'workingCount' => $lastScan->workingCount,
                    'completedAt' => $lastScan->completedAt,
                ] : null,
            ]);
        }

        $progress = 0;
        if ($runningScan->totalLinksFound > 0) {
            $progress = $runningScan->totalLinksChecked / $runningScan->totalLinksFound;
        }

        return $this->asJson([
            'running' => true,
            'progress' => round($progress * 100),
            'totalLinksFound' => $runningScan->totalLinksFound,
            'totalLinksChecked' => $runningScan->totalLinksChecked,
        ]);
    }

    /**
     * Ignore a link (AJAX).
     */
    public function actionIgnoreLink(): Response
    {
        $this->requirePermission('appleseed-runScans');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $linkId = Craft::$app->getRequest()->getRequiredBodyParam('linkId');
        $linkRecord = LinkRecord::findOne($linkId);

        if (!$linkRecord) {
            return $this->asJson(['success' => false, 'message' => 'Link not found']);
        }

        $linkRecord->isIgnored = true;
        $linkRecord->status = 'ignored';
        $linkRecord->save(false);

        return $this->asJson(['success' => true]);
    }

    /**
     * Un-ignore a link (AJAX).
     */
    public function actionUnignoreLink(): Response
    {
        $this->requirePermission('appleseed-runScans');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $linkId = Craft::$app->getRequest()->getRequiredBodyParam('linkId');
        $linkRecord = LinkRecord::findOne($linkId);

        if (!$linkRecord) {
            return $this->asJson(['success' => false, 'message' => 'Link not found']);
        }

        $linkRecord->isIgnored = false;
        $linkRecord->status = 'unknown';
        $linkRecord->save(false);

        return $this->asJson(['success' => true]);
    }

    /**
     * Re-scan a single link (AJAX).
     */
    public function actionRescanLink(): Response
    {
        $this->requirePermission('appleseed-runScans');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $linkId = (int) Craft::$app->getRequest()->getRequiredBodyParam('linkId');

        Craft::$app->getQueue()->push(new CheckLinksJob([
            'linkIds' => [$linkId],
        ]));

        return $this->asJson(['success' => true, 'message' => 'Re-check queued']);
    }

    /**
     * Export CSV.
     */
    public function actionExportCsv(): Response
    {
        $this->requirePermission('appleseed-viewDashboard');

        $statusFilter = Craft::$app->getRequest()->getQueryParam('status');
        $csv = Plugin::getInstance()->reporting->generateCsv($statusFilter);

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="appleseed-links-' . date('Y-m-d') . '.csv"');
        $response->content = $csv;

        return $response;
    }
}
