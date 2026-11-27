<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Http\Controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Plugin\PluginSettingsUi;
use JustinHoltWeb\Appleseed\Plugin;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Appleseed → Settings. Admins (where admin changes are allowed) go to Craft's standard settings
 * screen, which saves; holders of "Manage Appleseed settings" see the same fields read-only,
 * because settings are project config, including the template that lays out the report email.
 */
class SettingsController
{
    public function __invoke(PluginSettingsUi $settingsUi): Response|CpScreenResponse
    {
        if (currentUser()?->isAdmin() && Cms::config()->allowAdminChanges) {
            return redirect(cp_url('settings/plugins/appleseed'));
        }

        return new CpScreenResponse()
            ->title(t('Settings', category: 'appleseed'))
            ->selectedSubnavItem('settings')
            ->crumbs([
                ['label' => t('Appleseed', category: 'appleseed'), 'url' => cp_url('appleseed/dashboard')],
            ])
            ->ui($settingsUi->render(Plugin::getInstance(), readOnly: true));
    }
}
