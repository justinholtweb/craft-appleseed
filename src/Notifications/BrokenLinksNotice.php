<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Notifications;

use CraftCms\Cms\Cp\Notifications\CpNotification;
use JustinHoltWeb\Appleseed\Models\Scan;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * A scan found broken links, in the control panel's notifications of everyone who can run scans
 * (the `notifyCpUsers` setting). New in Appleseed 6.
 */
class BrokenLinksNotice extends CpNotification
{
    public function __construct(Scan $scan, string $headline)
    {
        parent::__construct($headline);

        $this
            ->kind('appleseed-scan')
            ->title(t('Broken links', category: 'appleseed'))
            ->byline(t('Scan #{id}', ['id' => $scan->id], category: 'appleseed'))
            ->icon('link-slash')
            ->url(cp_url('appleseed/dashboard', ['status' => 'broken']));
    }
}
