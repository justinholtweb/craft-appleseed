<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Services\Reporting;
use JustinHoltWeb\Appleseed\Services\Scanner;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * One link's detail screen, and ignoring, un-ignoring and re-checking links.
 */
class LinksController
{
    public function show(int $linkId, Reporting $reporting): CpScreenResponse
    {
        $link = $reporting->getLinkDetail($linkId) ?? abort(404, t('Link not found.', category: 'appleseed'));

        return new CpScreenResponse()
            ->title(t('Link Detail', category: 'appleseed'))
            ->selectedSubnavItem('dashboard')
            ->crumbs([
                ['label' => t('Appleseed', category: 'appleseed'), 'url' => cp_url('appleseed/dashboard')],
            ])
            ->contentTemplate('appleseed/dashboard/_detail', [
                'link' => $link,
                'canScan' => (bool) currentUser()?->can(Plugin::PERMISSION_SCAN),
            ]);
    }

    public function ignore(Request $request, Scanner $scanner): JsonResponse
    {
        $scanner->ignoreLink(self::link($request), currentUser()?->getCraftUserId());

        return response()->json(['success' => true, 'message' => t('Link ignored.', category: 'appleseed')]);
    }

    public function unignore(Request $request, Scanner $scanner): JsonResponse
    {
        $scanner->unignoreLink(self::link($request));

        return response()->json(['success' => true, 'message' => t('Link un-ignored. The next scan checks it again.', category: 'appleseed')]);
    }

    public function rescan(Request $request, Scanner $scanner): JsonResponse
    {
        $scanner->queueLinkChecks([self::link($request)->id]);

        return response()->json(['success' => true, 'message' => t('Re-check queued.', category: 'appleseed')]);
    }

    private static function link(Request $request): Link
    {
        $link = Link::query()->find($request->integer('linkId'));

        return $link instanceof Link ? $link : abort(404, t('Link not found.', category: 'appleseed'));
    }
}
