<?php

namespace justinholtweb\appleseed\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\appleseed\jobs\CheckLinksJob;
use justinholtweb\appleseed\jobs\ScanJob;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use yii\web\NotFoundHttpException;
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

        // Group sections by type for scan scope picker
        $sectionsByType = $this->_getSectionsByType();

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
            'sectionsByType' => $sectionsByType,
        ]);
    }

    /**
     * Preview the scan report email in the browser.
     */
    public function actionPreviewEmail(): Response
    {
        $plugin = Plugin::getInstance();
        $scan = $plugin->reporting->getLastCompletedScan();

        if (!$scan) {
            Craft::$app->getSession()->setError(Craft::t('appleseed', 'No completed scan found to preview.'));
            return $this->redirect('appleseed/dashboard');
        }

        $html = $plugin->reporting->renderScanReportEmail($scan);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_HTML;
        $response->content = $html;

        return $response;
    }

    /**
     * Link detail page.
     */
    public function actionDetail(int $linkId): Response
    {
        $plugin = Plugin::getInstance();
        $link = $plugin->reporting->getLinkDetail($linkId);

        if (!$link) {
            throw new NotFoundHttpException(Craft::t('appleseed', 'Link not found.'));
        }

        return $this->renderTemplate('appleseed/dashboard/_detail', [
            'link' => $link,
        ]);
    }

    /**
     * Start a scan (AJAX). Optionally scoped to specific sections.
     */
    public function actionRunScan(): Response
    {
        $this->requirePermission('appleseed-runScans');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sectionIds = Craft::$app->getRequest()->getBodyParam('sectionIds');

        $job = new ScanJob();
        if (!empty($sectionIds) && is_array($sectionIds)) {
            $job->sectionIds = array_map('intval', $sectionIds);
        }

        Craft::$app->getQueue()->push($job);

        return $this->asJson(['success' => true, 'message' => Craft::t('appleseed', 'Scan queued.')]);
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
            return $this->asJson(['success' => false, 'message' => Craft::t('appleseed', 'Link not found.')]);
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
            return $this->asJson(['success' => false, 'message' => Craft::t('appleseed', 'Link not found.')]);
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

        return $this->asJson(['success' => true, 'message' => Craft::t('appleseed', 'Re-check queued.')]);
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

    /**
     * Get all sections grouped by type for the scan scope picker.
     *
     * @return array<string, array<array{id: int, name: string}>>
     */
    private function _getSectionsByType(): array
    {
        $sections = Craft::$app->getEntries()->getAllSections();
        $grouped = [];

        foreach ($sections as $section) {
            $type = is_string($section->type) ? $section->type : $section->type->value;
            $grouped[$type][] = [
                'id' => $section->id,
                'name' => $section->name,
            ];
        }

        // Sort by display order: singles, channels, structures
        $ordered = [];
        foreach (['single', 'channel', 'structure'] as $type) {
            if (isset($grouped[$type])) {
                $ordered[$type] = $grouped[$type];
            }
        }

        return $ordered;
    }
}
