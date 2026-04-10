<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\ScanRecord;
use yii\db\Query;

class Reporting extends Component
{
    public function getBrokenLinkCount(): int
    {
        return LinkRecord::find()
            ->where(['status' => ['broken', 'dns_error', 'timeout', 'server_error']])
            ->andWhere(['isIgnored' => false])
            ->count();
    }

    /**
     * Get summary counts for the dashboard.
     */
    public function getDashboardSummary(): array
    {
        $total = LinkRecord::find()->where(['isIgnored' => false])->count();
        $broken = LinkRecord::find()->where(['status' => ['broken', 'dns_error', 'timeout', 'server_error']])->andWhere(['isIgnored' => false])->count();
        $redirects = LinkRecord::find()->where(['status' => 'redirect'])->andWhere(['isIgnored' => false])->count();
        $working = LinkRecord::find()->where(['status' => 'working'])->andWhere(['isIgnored' => false])->count();
        $ignored = LinkRecord::find()->where(['isIgnored' => true])->count();

        return [
            'total' => $total,
            'broken' => $broken,
            'redirects' => $redirects,
            'working' => $working,
            'ignored' => $ignored,
        ];
    }

    /**
     * Get paginated, filterable link results for the dashboard table.
     */
    public function getLinkResults(?string $statusFilter = null, ?string $search = null, int $page = 1, int $perPage = 50, string $sort = 'status', string $direction = 'asc'): array
    {
        $query = (new Query())
            ->from(['l' => '{{%appleseed_links}}'])
            ->where(['l.isIgnored' => false]);

        if ($statusFilter) {
            if ($statusFilter === 'broken') {
                $query->andWhere(['l.status' => ['broken', 'dns_error', 'timeout', 'server_error']]);
            } else {
                $query->andWhere(['l.status' => $statusFilter]);
            }
        }

        if ($search) {
            $query->andWhere(['like', 'l.url', $search]);
        }

        $total = $query->count();

        // Get link records
        $allowedSorts = ['url', 'status', 'statusCode', 'lastCheckedAt'];
        $sortCol = in_array($sort, $allowedSorts, true) ? "l.{$sort}" : 'l.status';
        $dir = strtolower($direction) === 'desc' ? SORT_DESC : SORT_ASC;

        $links = $query
            ->select(['l.*'])
            ->orderBy([$sortCol => $dir])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        // Enrich with source data for each link
        foreach ($links as &$link) {
            $sources = (new Query())
                ->from(['ls' => '{{%appleseed_link_sources}}'])
                ->where(['ls.linkId' => $link['id']])
                ->all();

            $link['sources'] = $this->_enrichSources($sources);
        }
        unset($link);

        return [
            'links' => $links,
            'total' => (int) $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get detail data for a single link.
     */
    public function getLinkDetail(int $linkId): ?array
    {
        $link = (new Query())
            ->from('{{%appleseed_links}}')
            ->where(['id' => $linkId])
            ->one();

        if (!$link) {
            return null;
        }

        $sources = (new Query())
            ->from(['ls' => '{{%appleseed_link_sources}}'])
            ->where(['ls.linkId' => $linkId])
            ->all();

        $link['sources'] = $this->_enrichSources($sources);
        $link['redirectChain'] = !empty($link['redirectChain']) ? json_decode($link['redirectChain'], true) : null;

        return $link;
    }

    /**
     * Generate CSV content from link results.
     * Outputs one row per link-source pair for easier filtering in spreadsheets.
     */
    public function generateCsv(?string $statusFilter = null): string
    {
        $query = (new Query())
            ->from(['l' => '{{%appleseed_links}}']);

        if ($statusFilter) {
            if ($statusFilter === 'broken') {
                $query->andWhere(['l.status' => ['broken', 'dns_error', 'timeout', 'server_error']]);
            } else {
                $query->andWhere(['l.status' => $statusFilter]);
            }
        }

        $links = $query->orderBy(['l.status' => SORT_ASC, 'l.url' => SORT_ASC])->all();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, [
            'URL',
            'Status',
            'Status Code',
            'Redirect URL',
            'Error',
            'Last Checked',
            'Ignored',
            'Entry Title',
            'Entry Section',
            'Entry Status',
            'Entry CP URL',
            'Field Handle',
            'Link Text',
            'Source Type',
            'Spider Source URL',
        ]);

        foreach ($links as $link) {
            $sources = (new Query())
                ->from('{{%appleseed_link_sources}}')
                ->where(['linkId' => $link['id']])
                ->all();

            $sources = $this->_enrichSources($sources);

            if (empty($sources)) {
                // Link with no sources — output a single row
                fputcsv($output, [
                    $link['url'],
                    $link['status'],
                    $link['statusCode'] ?? '',
                    $link['redirectUrl'] ?? '',
                    $link['errorMessage'] ?? '',
                    $link['lastCheckedAt'] ?? '',
                    $link['isIgnored'] ? 'Yes' : 'No',
                    '', '', '', '', '', '', '', '',
                ]);
                continue;
            }

            foreach ($sources as $source) {
                fputcsv($output, [
                    $link['url'],
                    $link['status'],
                    $link['statusCode'] ?? '',
                    $link['redirectUrl'] ?? '',
                    $link['errorMessage'] ?? '',
                    $link['lastCheckedAt'] ?? '',
                    $link['isIgnored'] ? 'Yes' : 'No',
                    $source['entryTitle'] ?? '',
                    $source['entrySection'] ?? '',
                    $source['entryStatus'] ?? '',
                    $source['entryCpUrl'] ?? '',
                    $source['fieldHandle'] ?? '',
                    $source['linkText'] ?? '',
                    $source['sourceType'] ?? '',
                    $source['sourceUrl'] ?? '',
                ]);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Enrich source records with entry metadata (title, section, status, CP URL).
     */
    private function _enrichSources(array $sources): array
    {
        foreach ($sources as &$source) {
            if (!empty($source['entryId'])) {
                $entry = Craft::$app->getEntries()->getEntryById($source['entryId'], $source['siteId']);
                if ($entry) {
                    $source['entryTitle'] = $entry->title;
                    $source['entryCpUrl'] = $entry->getCpEditUrl();
                    $source['entrySection'] = $entry->getSection()?->name;
                    $source['entryStatus'] = $entry->getStatus();
                } else {
                    $source['entryTitle'] = null;
                    $source['entryCpUrl'] = null;
                    $source['entrySection'] = null;
                    $source['entryStatus'] = 'deleted';
                }
            }
        }
        unset($source);

        return $sources;
    }

    /**
     * Get the last completed scan.
     */
    public function getLastCompletedScan(): ?ScanRecord
    {
        return ScanRecord::find()
            ->where(['status' => 'completed'])
            ->orderBy(['completedAt' => SORT_DESC])
            ->one();
    }

    /**
     * Get the currently running scan (if any).
     */
    public function getRunningScan(): ?ScanRecord
    {
        return ScanRecord::find()
            ->where(['status' => 'running'])
            ->orderBy(['startedAt' => SORT_DESC])
            ->one();
    }

    /**
     * Render the scan report email HTML.
     */
    public function renderScanReportEmail(ScanRecord $scan): string
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();

        $summary = $this->getDashboardSummary();
        $cpUrl = rtrim(UrlHelper::cpUrl(), '/');

        // Fetch broken links with source details (capped at 25 for email)
        $maxBrokenInEmail = 25;
        $brokenLinks = (new Query())
            ->from(['l' => '{{%appleseed_links}}'])
            ->where(['l.status' => ['broken', 'dns_error', 'timeout', 'server_error']])
            ->andWhere(['l.isIgnored' => false])
            ->orderBy(['l.statusCode' => SORT_ASC, 'l.url' => SORT_ASC])
            ->limit($maxBrokenInEmail)
            ->all();

        foreach ($brokenLinks as &$link) {
            $sources = (new Query())
                ->from(['ls' => '{{%appleseed_link_sources}}'])
                ->where(['ls.linkId' => $link['id']])
                ->all();
            $link['sources'] = $this->_enrichSources($sources);
        }
        unset($link);

        // Render the content body in CP template mode
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $content = $view->renderTemplate('appleseed/email/scan-report', [
            'scan' => $scan,
            'summary' => $summary,
            'cpUrl' => $cpUrl,
            'brokenLinks' => $brokenLinks,
            'brokenLinkCount' => $summary['broken'],
        ]);

        // Render the layout wrapper
        $layoutTemplate = $settings->getEmailLayoutTemplateParsed();
        $layoutVars = [
            'content' => $content,
            'scan' => $scan,
            'summary' => $summary,
            'cpUrl' => $cpUrl,
        ];

        if (!empty($layoutTemplate)) {
            // Custom layout lives in the site templates directory
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $html = $view->renderTemplate($layoutTemplate, $layoutVars);
        } else {
            $html = $view->renderTemplate('appleseed/email/_default-layout', $layoutVars);
        }

        $view->setTemplateMode($oldMode);

        return $html;
    }

    /**
     * Send email notification about scan results.
     */
    public function sendScanNotification(ScanRecord $scan): void
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $emails = $settings->getNotificationEmailsArray();

        if (empty($emails)) {
            return;
        }

        $html = $this->renderScanReportEmail($scan);

        foreach ($emails as $email) {
            try {
                Craft::$app->getMailer()
                    ->compose()
                    ->setTo($email)
                    ->setSubject("Appleseed: {$scan->brokenCount} broken link(s) found")
                    ->setHtmlBody($html)
                    ->send();
            } catch (\Throwable $e) {
                Craft::warning("Appleseed: Failed to send notification to {$email}: {$e->getMessage()}", __METHOD__);
            }
        }
    }
}
