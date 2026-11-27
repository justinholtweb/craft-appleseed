<?php

namespace justinholtweb\appleseed\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\models\Settings;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use justinholtweb\appleseed\records\ScanRecord;
use yii\db\Query;

class Reporting extends Component
{
    /**
     * Filters the link listings accept. All optional.
     *
     * - `status`: a status, `broken` for every broken status, or `ignored` for ignored links
     * - `search`: part of the URL
     * - `host`: the link's host, exactly
     * - `siteId`: only links found on this site
     * - `entryId`: only links found in this entry
     * - `includeIgnored`: with no status filter, include ignored links too
     */
    public const FILTER_KEYS = ['status', 'search', 'host', 'siteId', 'entryId'];

    public function getBrokenLinkCount(): int
    {
        return (int)LinkRecord::find()
            ->where(['status' => LinkStatus::BROKEN_STATUSES])
            ->andWhere(['isIgnored' => false])
            ->count();
    }

    /**
     * Get summary counts for the dashboard.
     *
     * @return array{total: int, broken: int, redirects: int, working: int, blocked: int, unchecked: int, ignored: int}
     */
    public function getDashboardSummary(?int $siteId = null): array
    {
        $query = (new Query())
            ->select(['l.status', 'l.isIgnored', 'count' => 'COUNT(*)'])
            ->from(['l' => '{{%appleseed_links}}'])
            ->groupBy(['l.status', 'l.isIgnored']);

        if ($siteId) {
            $query->where($this->_siteCondition($siteId));
        }

        $summary = ['total' => 0, 'broken' => 0, 'redirects' => 0, 'working' => 0, 'blocked' => 0, 'unchecked' => 0, 'ignored' => 0];

        foreach ($query->all() as $row) {
            $count = (int)$row['count'];

            if ($row['isIgnored']) {
                $summary['ignored'] += $count;
                continue;
            }

            $summary['total'] += $count;

            match (true) {
                LinkStatus::isBroken($row['status']) => $summary['broken'] += $count,
                $row['status'] === LinkStatus::REDIRECT => $summary['redirects'] += $count,
                $row['status'] === LinkStatus::WORKING => $summary['working'] += $count,
                $row['status'] === LinkStatus::BLOCKED => $summary['blocked'] += $count,
                $row['status'] === LinkStatus::UNKNOWN => $summary['unchecked'] += $count,
                default => null,
            };
        }

        return $summary;
    }

    /**
     * Get paginated, filterable link results for the dashboard table.
     *
     * @param string|null $statusFilter
     * @param array<string, mixed> $filters Extra filters: `host`, `siteId`, `entryId` (see {@see FILTER_KEYS}).
     */
    public function getLinkResults(?string $statusFilter = null, ?string $search = null, int $page = 1, int $perPage = 50, string $sort = 'status', string $direction = 'asc', array $filters = []): array
    {
        $query = $this->linkQuery(['status' => $statusFilter, 'search' => $search] + $filters);

        $total = (int)$query->count();

        $allowedSorts = ['url', 'status', 'statusCode', 'lastCheckedAt', 'host', 'firstBrokenAt'];
        $sortCol = in_array($sort, $allowedSorts, true) ? "l.{$sort}" : 'l.status';
        $dir = strtolower($direction) === 'desc' ? SORT_DESC : SORT_ASC;

        $links = $query
            ->select(['l.*'])
            ->orderBy([$sortCol => $dir, 'l.id' => SORT_ASC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        $links = $this->attachSources($links, isset($filters['siteId']) ? (int)$filters['siteId'] : null);

        return [
            'links' => $links,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * A query on `appleseed_links` (aliased `l`) with the given filters applied.
     *
     * @param array<string, mixed> $filters See {@see FILTER_KEYS}.
     */
    public function linkQuery(array $filters = []): Query
    {
        $status = isset($filters['status']) && $filters['status'] !== '' ? (string)$filters['status'] : null;
        $query = (new Query())->from(['l' => '{{%appleseed_links}}']);

        // `includeIgnored` only applies without a status filter
        if ($status !== null || empty($filters['includeIgnored'])) {
            $query->where(LinkStatus::condition($status));
        }

        if (!empty($filters['search'])) {
            $query->andWhere(['like', 'l.url', (string)$filters['search']]);
        }

        if (!empty($filters['host'])) {
            $query->andWhere(['l.host' => strtolower((string)$filters['host'])]);
        }

        if (!empty($filters['siteId'])) {
            $query->andWhere($this->_siteCondition((int)$filters['siteId']));
        }

        if (!empty($filters['entryId'])) {
            $query->andWhere(['exists', (new Query())
                ->from(['es' => '{{%appleseed_link_sources}}'])
                ->where('[[es.linkId]] = [[l.id]]')
                ->andWhere(['es.entryId' => (int)$filters['entryId']]),
            ]);
        }

        return $query;
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

        [$link] = $this->attachSources([$link]);
        $link['redirectChain'] = !empty($link['redirectChain']) ? json_decode($link['redirectChain'], true) : null;
        $link['ignoredBy'] = !empty($link['ignoredById']) ? User::find()->id($link['ignoredById'])->status(null)->one() : null;

        return $link;
    }

    /**
     * Find a link by its exact URL.
     */
    public function getLinkIdByUrl(string $url): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from('{{%appleseed_links}}')
            ->where(['urlHash' => hash('sha256', trim($url))])
            ->scalar();

        return $id ? (int)$id : null;
    }

    /**
     * The links found in one entry, with how each fares.
     *
     * @param bool $problemsOnly Only broken links, redirects and blocked links.
     * @return array<array<string, mixed>>
     */
    public function getEntryLinks(int $entryId, ?int $siteId = null, bool $problemsOnly = false): array
    {
        $sources = (new Query())
            ->select(['ls.linkId', 'ls.fieldHandle', 'ls.linkText'])
            ->from(['ls' => '{{%appleseed_link_sources}}'])
            ->where(['ls.entryId' => $entryId, 'ls.sourceType' => 'database']);

        if ($siteId) {
            $sources->andWhere(['ls.siteId' => $siteId]);
        }

        $byLink = [];
        foreach ($sources->all() as $source) {
            $byLink[(int)$source['linkId']][] = $source;
        }

        if ($byLink === []) {
            return [];
        }

        $query = (new Query())
            ->from(['l' => '{{%appleseed_links}}'])
            ->where(['l.id' => array_keys($byLink)])
            ->orderBy(['l.status' => SORT_ASC, 'l.url' => SORT_ASC]);

        if ($problemsOnly) {
            $query->andWhere(['l.isIgnored' => false])
                ->andWhere(['l.status' => [...LinkStatus::BROKEN_STATUSES, LinkStatus::REDIRECT, LinkStatus::BLOCKED]]);
        }

        $links = $query->all();
        foreach ($links as &$link) {
            $link['fields'] = array_values(array_unique(array_filter(array_column($byLink[(int)$link['id']], 'fieldHandle'))));
            $link['linkText'] = $byLink[(int)$link['id']][0]['linkText'] ?? null;
        }
        unset($link);

        return $links;
    }

    /**
     * Hosts with the most broken links — usually a site that moved or went away.
     *
     * @return array<array{host: string, broken: int}>
     */
    public function getWorstHosts(int $limit = 10, ?int $siteId = null): array
    {
        $query = $this->linkQuery(['status' => LinkStatus::BROKEN, 'siteId' => $siteId])
            ->select(['host' => 'l.host', 'broken' => 'COUNT(*)'])
            ->andWhere(['not', ['l.host' => null]])
            ->groupBy(['l.host'])
            ->orderBy(['broken' => SORT_DESC, 'host' => SORT_ASC])
            ->limit($limit);

        return array_map(fn(array $row) => ['host' => (string)$row['host'], 'broken' => (int)$row['broken']], $query->all());
    }

    /**
     * Entries with the most broken links: where fixing pays off first.
     *
     * @return array<array{entryId: int, siteId: int|null, broken: int, title: string|null, cpUrl: string|null}>
     */
    public function getWorstEntries(int $limit = 10, ?int $siteId = null): array
    {
        $query = (new Query())
            ->select(['entryId' => 'ls.entryId', 'siteId' => 'MIN([[ls.siteId]])', 'broken' => 'COUNT(DISTINCT [[l.id]])'])
            ->from(['ls' => '{{%appleseed_link_sources}}'])
            ->innerJoin(['l' => '{{%appleseed_links}}'], '[[l.id]] = [[ls.linkId]]')
            ->where(['l.status' => LinkStatus::BROKEN_STATUSES, 'l.isIgnored' => false])
            ->andWhere(['not', ['ls.entryId' => null]])
            ->groupBy(['ls.entryId'])
            ->orderBy(['broken' => SORT_DESC, 'entryId' => SORT_ASC])
            ->limit($limit);

        if ($siteId) {
            $query->andWhere(['ls.siteId' => $siteId]);
        }

        $rows = $query->all();
        $entries = $this->_loadEntries(array_map(fn($row) => ['entryId' => $row['entryId'], 'siteId' => $row['siteId']], $rows));

        return array_map(function(array $row) use ($entries) {
            $entry = $entries["{$row['siteId']}:{$row['entryId']}"] ?? null;

            return [
                'entryId' => (int)$row['entryId'],
                'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
                'broken' => (int)$row['broken'],
                'title' => $entry?->title,
                'cpUrl' => $entry?->getCpEditUrl(),
            ];
        }, $rows);
    }

    /**
     * Hosts that links point at, for the dashboard's host filter.
     *
     * @return string[]
     */
    public function getHosts(int $limit = 200): array
    {
        return array_map('strval', (new Query())
            ->select(['host'])
            ->from('{{%appleseed_links}}')
            ->where(['not', ['host' => null]])
            ->andWhere(['isIgnored' => false])
            ->groupBy(['host'])
            ->orderBy(['COUNT(*)' => SORT_DESC])
            ->limit($limit)
            ->column());
    }

    /**
     * Scans, newest first.
     *
     * @return ScanRecord[]
     */
    public function getScans(int $limit = 20, int $offset = 0): array
    {
        /** @var ScanRecord[] $scans */
        $scans = ScanRecord::find()
            ->orderBy(['id' => SORT_DESC])
            ->offset(max(0, $offset))
            ->limit(max(1, min(100, $limit)))
            ->all();

        return $scans;
    }

    public function getTotalScans(): int
    {
        return (int)ScanRecord::find()->count();
    }

    public function getScanById(int $id): ?ScanRecord
    {
        return ScanRecord::findOne($id);
    }

    /**
     * Generate CSV content from link results.
     * Outputs one row per link-source pair for easier filtering in spreadsheets.
     *
     * @param array<string, mixed> $filters Extra filters (see {@see FILTER_KEYS}). Without a
     * status filter every link is exported, ignored ones included.
     */
    public function generateCsv(?string $statusFilter = null, array $filters = []): string
    {
        // No status chosen: every link, ignored ones included (as before 5.3.0)
        $query = $this->linkQuery(['status' => $statusFilter, 'includeIgnored' => true] + $filters);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, [
            'URL',
            'Status',
            'Status Code',
            'Redirect URL',
            'Error',
            'Last Checked',
            'Broken Since',
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

        $siteId = isset($filters['siteId']) ? (int)$filters['siteId'] : null;

        foreach ($query->select(['l.*'])->orderBy(['l.status' => SORT_ASC, 'l.url' => SORT_ASC])->batch(200) as $batch) {
            foreach ($this->attachSources($batch, $siteId) as $link) {
                $base = [
                    $link['url'],
                    $link['status'],
                    $link['statusCode'] ?? '',
                    $link['redirectUrl'] ?? '',
                    $link['errorMessage'] ?? '',
                    $link['lastCheckedAt'] ?? '',
                    $link['firstBrokenAt'] ?? '',
                    $link['isIgnored'] ? 'Yes' : 'No',
                ];

                if (empty($link['sources'])) {
                    fputcsv($output, [...$base, '', '', '', '', '', '', '', '']);
                    continue;
                }

                foreach ($link['sources'] as $source) {
                    fputcsv($output, [
                        ...$base,
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
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return (string)$csv;
    }

    /**
     * Attach each link's sources, with entry metadata, in two queries rather than one per link.
     *
     * @param array<array<string, mixed>> $links Rows from `appleseed_links`.
     * @param int|null $siteId Only sources on this site.
     * @return array<array<string, mixed>>
     */
    public function attachSources(array $links, ?int $siteId = null): array
    {
        if ($links === []) {
            return [];
        }

        $query = (new Query())
            ->from(['ls' => '{{%appleseed_link_sources}}'])
            ->where(['ls.linkId' => array_column($links, 'id')])
            ->orderBy(['ls.id' => SORT_ASC]);

        if ($siteId) {
            $query->andWhere(['or', ['ls.siteId' => $siteId], ['ls.sourceType' => 'spider']]);
        }

        $sources = $this->_enrichSources($query->all());
        $byLink = [];
        foreach ($sources as $source) {
            $byLink[(int)$source['linkId']][] = $source;
        }

        foreach ($links as &$link) {
            $link['sources'] = $byLink[(int)$link['id']] ?? [];
        }
        unset($link);

        return $links;
    }

    /**
     * Enrich source records with entry metadata (title, section, status, CP URL).
     *
     * @param array<array<string, mixed>> $sources
     * @return array<array<string, mixed>>
     */
    private function _enrichSources(array $sources): array
    {
        $entries = $this->_loadEntries(array_filter($sources, fn($s) => !empty($s['entryId'])));

        foreach ($sources as &$source) {
            if (empty($source['entryId'])) {
                continue;
            }

            $entry = $entries["{$source['siteId']}:{$source['entryId']}"] ?? null;
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
        unset($source);

        return $sources;
    }

    /**
     * Load entries by ID and site, keyed `"{siteId}:{entryId}"`.
     *
     * @param array<array{entryId: mixed, siteId: mixed}> $pairs
     * @return array<string, Entry>
     */
    private function _loadEntries(array $pairs): array
    {
        $bySite = [];
        foreach ($pairs as $pair) {
            if (!empty($pair['entryId'])) {
                $bySite[(int)($pair['siteId'] ?? 0)][(int)$pair['entryId']] = true;
            }
        }

        $entries = [];
        foreach ($bySite as $siteId => $ids) {
            $query = Entry::find()->id(array_keys($ids))->status(null)->drafts(null)->provisionalDrafts(null)->limit(null);
            $query->siteId($siteId ?: '*');

            foreach ($query->all() as $entry) {
                $entries["{$siteId}:{$entry->id}"] ??= $entry;
            }
        }

        return $entries;
    }

    /**
     * Links with at least one source on the site (spidered pages count for every site).
     *
     * @return array<mixed>
     */
    private function _siteCondition(int $siteId): array
    {
        return ['exists', (new Query())
            ->from(['ss' => '{{%appleseed_link_sources}}'])
            ->where('[[ss.linkId]] = [[l.id]]')
            ->andWhere(['or', ['ss.siteId' => $siteId], ['ss.sourceType' => 'spider']]),
        ];
    }

    /**
     * Get the last completed scan.
     *
     * @param string[]|null $types Only scans of these types.
     */
    public function getLastCompletedScan(?array $types = null): ?ScanRecord
    {
        $query = ScanRecord::find()
            ->where(['status' => Scanner::STATUS_COMPLETED])
            ->orderBy(['completedAt' => SORT_DESC]);

        if ($types !== null) {
            $query->andWhere(['type' => $types]);
        }

        /** @var ScanRecord|null $scan */
        $scan = $query->one();

        return $scan;
    }

    /**
     * Get the currently running scan (if any). Scans whose queue job died are marked failed first.
     */
    public function getRunningScan(): ?ScanRecord
    {
        Plugin::getInstance()->scanner->failAbandonedScans();

        /** @var ScanRecord|null $scan */
        $scan = ScanRecord::find()
            ->where(['status' => Scanner::STATUS_RUNNING])
            ->orderBy(['startedAt' => SORT_DESC])
            ->one();

        return $scan;
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
        $query = (new Query())
            ->from(['l' => '{{%appleseed_links}}'])
            ->where(['l.status' => LinkStatus::BROKEN_STATUSES])
            ->andWhere(['l.isIgnored' => false])
            ->orderBy(['l.statusCode' => SORT_ASC, 'l.url' => SORT_ASC])
            ->limit($maxBrokenInEmail);

        if ($settings->notifyOnlyNew && $scan->startedAt) {
            $query->andWhere(['>=', 'l.firstBrokenAt', $scan->startedAt]);
        }

        $brokenLinks = $this->attachSources($query->all());

        // Render the content body in CP template mode
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $content = $view->renderTemplate('appleseed/email/scan-report', [
            'scan' => $scan,
            'summary' => $summary,
            'cpUrl' => $cpUrl,
            'brokenLinks' => $brokenLinks,
            'brokenLinkCount' => $summary['broken'],
            'onlyNew' => $settings->notifyOnlyNew,
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
        $subject = $settings->notifyOnlyNew
            ? Craft::t('appleseed', 'Appleseed: {count, plural, =1{# link} other{# links}} newly broken', ['count' => $scan->newBrokenCount])
            : Craft::t('appleseed', 'Appleseed: {count, plural, =1{# broken link} other{# broken links}} found', ['count' => $scan->brokenCount]);

        foreach ($emails as $email) {
            try {
                Craft::$app->getMailer()
                    ->compose()
                    ->setTo($email)
                    ->setSubject($subject)
                    ->setHtmlBody($html)
                    ->send();
            } catch (\Throwable $e) {
                Craft::warning("Appleseed: Failed to send notification to {$this->_maskEmail($email)}: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * Mask an email for logs: "alice@example.com" -> "a***@example.com".
     */
    private function _maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
