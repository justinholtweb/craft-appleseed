<?php

namespace justinholtweb\appleseed\mcp;

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\McpActor;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\helpers\Presenter;
use justinholtweb\appleseed\helpers\ScanType;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Appleseed's tools for Craft's MCP server (Craft 5.12.1+; registered by Plugin::_registerMcp()).
 *
 * Every tool acts as the MCP actor and makes the permission checks the control panel makes.
 * Plugin services are reached through Plugin::getInstance(): the MCP container would autowire
 * fresh, unconfigured instances if they were constructor arguments.
 *
 * Tool names and output shapes are final — public approvals are stored by name — and shared with
 * Appleseed 6.
 */
class AppleseedTools
{
    /** For optional filters: `null` must be in the enum too, or an explicit null is rejected. */
    private const STATUS_FILTER = [...LinkStatus::ALL, null];

    public function __construct(private McpActor $actor)
    {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_status',
        description: 'How the site’s links stand: counts of working, broken, redirecting, blocked (bot protection answered instead of the site; not counted as broken), unchecked and ignored links; the latest completed scan and any running one (with progress); the hosts and entries with the most broken links; and the scan schedule.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function status(
        #[Schema(minimum: 1, description: 'Only links found on this site.')]
        ?int $siteId = null,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);
        $plugin = Plugin::getInstance();
        $reporting = $plugin->reporting;
        $settings = $plugin->getSettings();
        $lastScan = $reporting->getLastCompletedScan();
        $running = $reporting->getRunningScan();

        return [
            'summary' => $reporting->getDashboardSummary($siteId),
            'latestScan' => $lastScan ? Presenter::scan($lastScan) : null,
            'runningScan' => $running ? Presenter::scan($running) + [
                'progressPercent' => $running->totalLinksFound > 0 ? (int)round($running->totalLinksChecked / $running->totalLinksFound * 100) : 0,
            ] : null,
            'worstHosts' => $reporting->getWorstHosts(5, $siteId),
            'worstEntries' => array_map(
                fn(array $entry) => array_diff_key($entry, ['cpUrl' => true]),
                $reporting->getWorstEntries(5, $siteId),
            ),
            'schedule' => [
                'frequency' => $settings->scanFrequency,
                'scanType' => $settings->scheduledScanType,
            ],
            'dashboardUrl' => UrlHelper::cpUrl('appleseed/dashboard'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_links_list',
        description: 'Lists links Appleseed has found, with each one’s check result (status, HTTP code, redirect target, error) and where it was found (entry ID and title, field, link text, or the crawled page). Filter by status — `broken` covers every broken status — host, part of the URL, site or entry. Ignored links are left out unless status is `ignored`. Page with offset until nextOffset is null.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function listLinks(
        #[Schema(enum: self::STATUS_FILTER, description: 'A status, `broken` for every broken status (broken, server_error, timeout, dns_error), or `ignored`.')]
        ?string $status = null,
        #[Schema(description: 'Part of the URL.')]
        ?string $search = null,
        #[Schema(description: 'The link’s host, exactly, e.g. `example.com`.')]
        ?string $host = null,
        #[Schema(minimum: 1, description: 'Only links found on this site.')]
        ?int $siteId = null,
        #[Schema(minimum: 1, description: 'Only links found in this entry (its canonical ID).')]
        ?int $entryId = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 25,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);
        $reporting = Plugin::getInstance()->reporting;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $query = $reporting->linkQuery([
            'status' => $status,
            'search' => $search,
            'host' => $host,
            'siteId' => $siteId,
            'entryId' => $entryId,
        ]);

        $total = (int)$query->count();
        $rows = $query->select(['l.*'])
            ->orderBy(['l.status' => SORT_ASC, 'l.id' => SORT_ASC])
            ->offset($offset)
            ->limit($limit)
            ->all();

        $links = array_map(
            fn(array $link) => Presenter::link($link) + ['cpUrl' => Presenter::linkCpUrl((int)$link['id'])],
            $reporting->attachSources($rows, $siteId),
        );

        return [
            'total' => $total,
            'count' => count($links),
            'nextOffset' => $offset + $limit < $total ? $offset + $limit : null,
            'links' => array_values($links),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_link_get',
        description: 'One link by ID or exact URL: its check result, the full redirect chain, when it started failing, who ignored it, and every place it was found.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function getLink(
        #[Schema(minimum: 1, description: 'The link’s ID, from appleseed_links_list.')]
        ?int $linkId = null,
        #[Schema(description: 'The exact URL, as Appleseed stored it.')]
        ?string $url = null,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);
        $reporting = Plugin::getInstance()->reporting;
        $linkId ??= $url !== null ? $reporting->getLinkIdByUrl($url) : null;
        $link = $linkId !== null ? $reporting->getLinkDetail($linkId) : null;

        if ($link === null) {
            throw new ToolCallException('Link not found. Pass linkId from appleseed_links_list, or the exact URL.');
        }

        /** @var User|null $ignoredBy */
        $ignoredBy = $link['ignoredBy'] ?? null;

        return Presenter::link($link) + [
            'ignoredBy' => $ignoredBy?->getName(),
            'cpUrl' => Presenter::linkCpUrl((int)$link['id']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_entry_links',
        description: 'The links in one entry’s fields (including Matrix and CKEditor nested entries), from the last scan that read it, with how each fared. Use it before or after editing an entry. Results are only as fresh as that scan; appleseed_entry_scan reads the entry again.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function entryLinks(
        #[Schema(minimum: 1, description: 'The entry’s canonical ID.')]
        int $entryId,
        #[Schema(minimum: 1)]
        ?int $siteId = null,
        #[Schema(description: 'Only broken links, redirects and blocked links.')]
        bool $problemsOnly = false,
    ): array {
        $user = $this->user(Plugin::PERMISSION_VIEW);
        $entry = $this->entry($entryId, $siteId, $user);

        $links = Plugin::getInstance()->reporting->getEntryLinks((int)$entry->id, $siteId, $problemsOnly);

        return [
            'entryId' => (int)$entry->id,
            'title' => $entry->title,
            'links' => array_values(array_map(fn(array $link) => Presenter::link($link, false) + [
                'fields' => $link['fields'],
                'linkText' => $link['linkText'],
            ], $links)),
        ];
    }

    /**
     * @return array{total: int, scans: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'appleseed_scans_list',
        description: 'Lists Appleseed scans, newest first: type, status (running, completed, failed, cancelled), links found and checked, and how many were broken, newly broken, redirecting or working.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function listScans(
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);
        $reporting = Plugin::getInstance()->reporting;

        return [
            'total' => $reporting->getTotalScans(),
            'scans' => array_values(array_map([Presenter::class, 'scan'], $reporting->getScans($limit, $offset))),
        ];
    }

    /**
     * @param int[]|null $sectionIds
     * @return array{queued: bool, type: string, message: string}
     */
    #[McpTool(
        name: 'appleseed_scan_run',
        description: 'Starts an Appleseed scan on Craft’s queue; it runs when the queue does, not here. Types: `full` (every live entry, plus crawling the site’s pages when the spider is on), `database` (every live entry, no crawl), `incremental` (entries changed since the last scan) or `recheck` (re-check links already known to be broken, reading no content). sectionIds limits a scan to sections. Only one scan runs at a time. Follow progress with appleseed_status.',
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function runScan(
        #[Schema(enum: ScanType::STARTABLE)]
        string $type = ScanType::FULL,
        #[Schema(items: ['type' => 'integer', 'minimum' => 1], description: 'Scan only these sections’ entries.')]
        ?array $sectionIds = null,
    ): array {
        $this->user(Plugin::PERMISSION_SCAN);

        if (!in_array($type, ScanType::STARTABLE, true)) {
            throw new ToolCallException(sprintf('Unknown scan type: %s', $type));
        }

        if (!Plugin::getInstance()->scanner->queueScan($type, $sectionIds ?: null)) {
            return [
                'queued' => false,
                'type' => $type,
                'message' => 'A scan is already running. Check its progress with appleseed_status, or cancel it with appleseed_scan_cancel.',
            ];
        }

        return [
            'queued' => true,
            'type' => $type,
            'message' => 'The scan is on the queue. appleseed_status shows its progress once the queue picks it up.',
        ];
    }

    /**
     * @return array{cancelled: int}
     */
    #[McpTool(
        name: 'appleseed_scan_cancel',
        description: 'Stops the running scan. Links it already checked keep their new results; the rest keep their previous ones.',
        annotations: new ToolAnnotations(idempotentHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function cancelScan(): array
    {
        $this->user(Plugin::PERMISSION_SCAN);

        return ['cancelled' => Plugin::getInstance()->scanner->cancelRunningScans()];
    }

    /**
     * @return array{queued: bool, entryId: int, message: string}
     */
    #[McpTool(
        name: 'appleseed_entry_scan',
        description: 'Queues a scan of one entry: reads its fields again and checks every link in them. Read the results with appleseed_entry_links once the queue has run it.',
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function scanEntry(
        #[Schema(minimum: 1, description: 'The entry’s canonical ID.')]
        int $entryId,
        #[Schema(minimum: 1)]
        ?int $siteId = null,
    ): array {
        $user = $this->user(Plugin::PERMISSION_SCAN);
        $entry = $this->entry($entryId, $siteId, $user);

        Plugin::getInstance()->scanner->queueEntryScan((int)$entry->id, $siteId);

        return [
            'queued' => true,
            'entryId' => (int)$entry->id,
            'message' => 'The entry scan is on the queue.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_link_recheck',
        description: 'Checks one known link again right now and returns the new result. The link must be one Appleseed has found (see appleseed_links_list).',
        annotations: new ToolAnnotations(idempotentHint: true, openWorldHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function recheckLink(
        #[Schema(minimum: 1)]
        int $linkId,
    ): array {
        $this->user(Plugin::PERMISSION_SCAN);
        $link = $this->linkRecord($linkId);

        if ($link->isIgnored) {
            throw new ToolCallException('The link is ignored. Stop ignoring it with appleseed_link_ignore first.');
        }

        Plugin::getInstance()->scanner->checkLink($link);
        $link->refresh();

        return Presenter::link($link->getAttributes(), false);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_url_check',
        description: 'Checks any http(s) URL now, without storing anything: status (working, broken, redirect, server_error, timeout, dns_error, blocked), HTTP code, redirect chain and error. Useful before putting a link into content. Private and internal addresses are refused.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function checkUrl(
        #[Schema(format: 'uri', maxLength: 2048, description: 'An http:// or https:// URL.')]
        string $url,
    ): array {
        $this->user(Plugin::PERMISSION_SCAN);
        $url = trim($url);

        if (!preg_match('#^https?://#i', $url)) {
            throw new ToolCallException('Only http:// and https:// URLs can be checked.');
        }

        // UrlGuard runs inside checkUrl(): internal addresses come back as `ignored` with the reason.
        $result = Plugin::getInstance()->linkChecker->checkUrl($url);

        return [
            'url' => $result->url,
            'status' => $result->status,
            'isBroken' => $result->isBroken(),
            'statusCode' => $result->statusCode,
            'redirectUrl' => $result->redirectUrl,
            'redirectChain' => $result->redirectChain ?? [],
            'errorMessage' => $result->errorMessage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'appleseed_link_ignore',
        description: 'Ignores a link (ignored: true) — it’s no longer checked, counted or reported, and stays ignored across scans — or stops ignoring it (ignored: false), so the next scan checks it again.',
        annotations: new ToolAnnotations(idempotentHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function ignoreLink(
        #[Schema(minimum: 1)]
        int $linkId,
        bool $ignored = true,
    ): array {
        $user = $this->user(Plugin::PERMISSION_SCAN);
        $link = $this->linkRecord($linkId);
        $scanner = Plugin::getInstance()->scanner;

        if ($ignored) {
            $scanner->ignoreLink($link, $user);
        } else {
            $scanner->unignoreLink($link);
        }

        return Presenter::link($link->getAttributes(), false);
    }

    private function linkRecord(int $linkId): LinkRecord
    {
        $link = LinkRecord::findOne($linkId);

        if ($link === null) {
            throw new ToolCallException('Link not found.');
        }

        return $link;
    }

    /**
     * An entry the actor may view.
     */
    private function entry(int $entryId, ?int $siteId, User $user): Entry
    {
        $entry = Entry::find()->id($entryId)->siteId($siteId ?? '*')->status(null)->one();

        if (!$entry instanceof Entry || !$entry->canView($user)) {
            throw new ToolCallException('Entry not found.');
        }

        return $entry;
    }

    /**
     * The actor, if they hold `$permission`. Craft's server already hides the tool from anyone
     * without it; this keeps the handler safe when it's called some other way.
     */
    private function user(string $permission): User
    {
        $user = $this->actor->user();

        if (!$user->can('accessCp') || !$user->can($permission)) {
            throw new ToolCallException('You don’t have permission to do that.');
        }

        return $user;
    }
}
