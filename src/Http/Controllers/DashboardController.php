<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use JustinHoltWeb\Appleseed\Models\Settings;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Support\LinkStatus;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * The dashboard: summary, where the broken links are, scan controls, and the filterable results.
 */
class DashboardController
{
    public function __invoke(Request $request, Reporting $reporting, Settings $settings): CpScreenResponse
    {
        $filters = self::filters($request, $settings);
        $siteId = $filters['siteId'] ?? null;
        $page = max(1, $request->integer('page', 1));
        $sort = (string) $request->query('sort', 'status');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        return new CpScreenResponse()
            ->title(t('Appleseed', category: 'appleseed'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('appleseed/dashboard/_content', [
                'summary' => $reporting->getDashboardSummary($siteId),
                'results' => $reporting->getLinkResults($filters, $page, 50, $sort, $direction),
                'lastScan' => $reporting->getLastCompletedScan(),
                'runningScan' => $reporting->getRunningScan(),
                'statusFilter' => $filters['status'],
                'search' => $filters['search'] ?? '',
                'host' => $filters['host'] ?? '',
                'siteId' => $siteId,
                'sort' => $sort,
                'direction' => $direction,
                'page' => $page,
                'sectionsByType' => self::sectionsByType(),
                'statusOptions' => LinkStatus::filterOptions(),
                'hosts' => $reporting->getHosts(),
                'sites' => Sites::isMultiSite() ? Sites::getAllSites()->all() : [],
                'worstHosts' => $reporting->getWorstHosts(5, $siteId),
                'worstEntries' => $reporting->getWorstEntries(5, $siteId),
                'canScan' => (bool) currentUser()?->can(Plugin::PERMISSION_SCAN),
            ]);
    }

    /**
     * The dashboard's filters from the query string. An absent `status` means "not chosen yet",
     * so the configured default applies; an empty one is an explicit "All Statuses".
     *
     * @return array{status: string, search?: string, host?: string, siteId?: int, entryId?: int}
     */
    public static function filters(Request $request, Settings $settings): array
    {
        $status = $request->query->has('status') ? (string) $request->query('status', '') : $settings->defaultStatus();
        $filters = ['status' => LinkStatus::normalizeFilter($status) ?? ''];

        foreach (['search', 'host'] as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $siteId = $request->integer('siteId');

        if ($siteId > 0 && Sites::getSiteById($siteId) !== null) {
            $filters['siteId'] = $siteId;
        }

        if (($entryId = $request->integer('entryId')) > 0) {
            $filters['entryId'] = $entryId;
        }

        return $filters;
    }

    /**
     * Sections for the scan scope picker: singles, channels, structures.
     *
     * @return array<string, list<array{id: int, name: string}>>
     */
    private static function sectionsByType(): array
    {
        $grouped = [];

        /** @var Section $section */
        foreach (Sections::getAllSections() as $section) {
            if ($section->type !== null && $section->id !== null) {
                $grouped[$section->type->value][] = ['id' => $section->id, 'name' => (string) $section->name];
            }
        }

        return array_filter([
            'single' => $grouped['single'] ?? [],
            'channel' => $grouped['channel'] ?? [],
            'structure' => $grouped['structure'] ?? [],
        ]);
    }
}
