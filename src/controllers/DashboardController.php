<?php

namespace justinholtweb\appleseed\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\models\Settings;
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

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /**
     * Main dashboard page.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $summarySiteId = (int)Craft::$app->getRequest()->getQueryParam('siteId') ?: null;
        $summary = $plugin->reporting->getDashboardSummary($summarySiteId);
        $lastScan = $plugin->reporting->getLastCompletedScan();
        $runningScan = $plugin->reporting->getRunningScan();

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        // An absent `status` param means "not chosen yet", so fall back to the configured
        // default. An empty one is an explicit "All Statuses" and is left alone.
        $statusFilter = Craft::$app->getRequest()->getQueryParam('status') ?? $settings->defaultStatusFilter;
        $search = Craft::$app->getRequest()->getQueryParam('search');
        $page = max(1, (int) Craft::$app->getRequest()->getQueryParam('page', 1));
        $sort = Craft::$app->getRequest()->getQueryParam('sort', 'status');
        $direction = Craft::$app->getRequest()->getQueryParam('direction', 'asc');

        $filters = $this->_extraFilters();
        $results = $plugin->reporting->getLinkResults($statusFilter, $search, $page, 50, $sort, $direction, $filters);

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
            'statusOptions' => $settings->getStatusFilterOptions(),
            'host' => $filters['host'] ?? null,
            'siteId' => $filters['siteId'] ?? null,
            'hosts' => $plugin->reporting->getHosts(),
            'sites' => Craft::$app->getIsMultiSite() ? Craft::$app->getSites()->getAllSites() : [],
            'worstHosts' => $plugin->reporting->getWorstHosts(5, $filters['siteId'] ?? null),
            'worstEntries' => $plugin->reporting->getWorstEntries(5, $filters['siteId'] ?? null),
            'canScan' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SCAN),
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
     * Start a scan (AJAX): `type` (full, database, incremental or recheck), optionally limited
     * to `sectionIds`.
     */
    public function actionRunScan(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_SCAN);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $type = (string)$request->getBodyParam('type', ScanType::FULL);
        $sectionIds = $request->getBodyParam('sectionIds');

        if (!in_array($type, ScanType::STARTABLE, true)) {
            return $this->asFailure(Craft::t('appleseed', 'Unknown scan type.'));
        }

        $queued = Plugin::getInstance()->scanner->queueScan(
            $type,
            !empty($sectionIds) && is_array($sectionIds) ? array_map('intval', $sectionIds) : null,
        );

        if (!$queued) {
            return $this->asFailure(Craft::t('appleseed', 'A scan is already running.'));
        }

        return $this->asJson(['success' => true, 'message' => Craft::t('appleseed', 'Scan queued.')]);
    }

    /**
     * Stop the running scan (AJAX).
     */
    public function actionCancelScan(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_SCAN);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $cancelled = Plugin::getInstance()->scanner->cancelRunningScans();

        return $this->asJson([
            'success' => true,
            'cancelled' => $cancelled,
            'message' => $cancelled ? Craft::t('appleseed', 'Scan cancelled.') : Craft::t('appleseed', 'No scan was running.'),
        ]);
    }

    /**
     * Queue a scan of one entry (AJAX), from the entry editor's sidebar.
     */
    public function actionScanEntry(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_SCAN);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $siteId = (int)$request->getBodyParam('siteId') ?: null;

        $entry = \craft\elements\Entry::find()->id($entryId)->siteId($siteId ?? '*')->status(null)->one();
        if (!$entry || !$entry->canView(Craft::$app->getUser()->getIdentity())) {
            throw new NotFoundHttpException(Craft::t('appleseed', 'Entry not found.'));
        }

        Plugin::getInstance()->scanner->queueEntryScan($entryId, $siteId);

        return $this->asJson(['success' => true, 'message' => Craft::t('appleseed', 'Entry scan queued. Reload the entry once the queue has run it.')]);
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
                    'type' => $lastScan->type,
                    'newBrokenCount' => $lastScan->newBrokenCount,
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
        $this->requirePermission(Plugin::PERMISSION_SCAN);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $linkId = Craft::$app->getRequest()->getRequiredBodyParam('linkId');
        $linkRecord = LinkRecord::findOne($linkId);

        if (!$linkRecord) {
            return $this->asJson(['success' => false, 'message' => Craft::t('appleseed', 'Link not found.')]);
        }

        Plugin::getInstance()->scanner->ignoreLink($linkRecord, Craft::$app->getUser()->getIdentity());

        return $this->asJson(['success' => true]);
    }

    /**
     * Un-ignore a link (AJAX).
     */
    public function actionUnignoreLink(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_SCAN);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $linkId = Craft::$app->getRequest()->getRequiredBodyParam('linkId');
        $linkRecord = LinkRecord::findOne($linkId);

        if (!$linkRecord) {
            return $this->asJson(['success' => false, 'message' => Craft::t('appleseed', 'Link not found.')]);
        }

        Plugin::getInstance()->scanner->unignoreLink($linkRecord);

        return $this->asJson(['success' => true]);
    }

    /**
     * Re-scan a single link (AJAX).
     */
    public function actionRescanLink(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_SCAN);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $linkId = (int) Craft::$app->getRequest()->getRequiredBodyParam('linkId');

        Plugin::getInstance()->scanner->queueLinkChecks([$linkId]);

        return $this->asJson(['success' => true, 'message' => Craft::t('appleseed', 'Re-check queued.')]);
    }

    /**
     * Export CSV.
     */
    public function actionExportCsv(): Response
    {
        $request = Craft::$app->getRequest();
        $statusFilter = $request->getQueryParam('status');
        $filters = $this->_extraFilters() + ['search' => $request->getQueryParam('search')];
        $csv = Plugin::getInstance()->reporting->generateCsv($statusFilter, $filters);

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="appleseed-links-' . date('Y-m-d') . '.csv"');
        $response->content = $csv;

        return $response;
    }

    /**
     * The host and site filters from the query string.
     *
     * @return array{host?: string, siteId?: int}
     */
    private function _extraFilters(): array
    {
        $request = Craft::$app->getRequest();
        $filters = [];

        $host = trim((string)$request->getQueryParam('host', ''));
        if ($host !== '') {
            $filters['host'] = $host;
        }

        $siteId = (int)$request->getQueryParam('siteId');
        if ($siteId && Craft::$app->getSites()->getSiteById($siteId)) {
            $filters['siteId'] = $siteId;
        }

        return $filters;
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
            $type = $section->type;
            if ($type === null) {
                continue;
            }
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
