<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\LinkSourceRecord;
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
        $sortCol = in_array($sort, $allowedSorts) ? "l.{$sort}" : 'l.status';
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

            // Get entry titles for source links
            foreach ($sources as &$source) {
                if (!empty($source['entryId'])) {
                    $entry = Craft::$app->getEntries()->getEntryById($source['entryId'], $source['siteId']);
                    $source['entryTitle'] = $entry?->title;
                    $source['entryCpUrl'] = $entry?->getCpEditUrl();
                }
            }
            unset($source);

            $link['sources'] = $sources;
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

        foreach ($sources as &$source) {
            if (!empty($source['entryId'])) {
                $entry = Craft::$app->getEntries()->getEntryById($source['entryId'], $source['siteId']);
                $source['entryTitle'] = $entry?->title;
                $source['entryCpUrl'] = $entry?->getCpEditUrl();
            }
        }
        unset($source);

        $link['sources'] = $sources;
        $link['redirectChain'] = !empty($link['redirectChain']) ? json_decode($link['redirectChain'], true) : null;

        return $link;
    }

    /**
     * Generate CSV content from link results.
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
        fputcsv($output, ['URL', 'Status', 'Status Code', 'Redirect URL', 'Error', 'Last Checked', 'Ignored', 'Sources']);

        foreach ($links as $link) {
            $sources = (new Query())
                ->from('{{%appleseed_link_sources}}')
                ->where(['linkId' => $link['id']])
                ->all();

            $sourceDescriptions = [];
            foreach ($sources as $source) {
                if (!empty($source['entryId'])) {
                    $entry = Craft::$app->getEntries()->getEntryById($source['entryId'], $source['siteId']);
                    $sourceDescriptions[] = ($entry?->title ?? "Entry #{$source['entryId']}") . " ({$source['fieldHandle']})";
                } elseif (!empty($source['sourceUrl'])) {
                    $sourceDescriptions[] = $source['sourceUrl'];
                }
            }

            fputcsv($output, [
                $link['url'],
                $link['status'],
                $link['statusCode'] ?? '',
                $link['redirectUrl'] ?? '',
                $link['errorMessage'] ?? '',
                $link['lastCheckedAt'] ?? '',
                $link['isIgnored'] ? 'Yes' : 'No',
                implode('; ', $sourceDescriptions),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
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

        $summary = $this->getDashboardSummary();

        $html = Craft::$app->getView()->renderTemplate('appleseed/email/scan-report', [
            'scan' => $scan,
            'summary' => $summary,
            'cpUrl' => Craft::$app->getConfig()->getGeneral()->cpUrl ?? Craft::$app->getRequest()->getHostInfo() . '/' . Craft::$app->getConfig()->getGeneral()->cpTrigger,
        ]);

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
