<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Jobs;

use CraftCms\Cms\Queue\Job;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Services\Scanner;
use Override;

use function CraftCms\Cms\t;

/**
 * Re-checks particular links outside of a scan (the dashboard's ↻ button). (`CheckLinksJob` in 5.)
 */
class CheckLinks extends Job
{
    /**
     * @param  list<int>  $linkIds
     */
    public function __construct(
        public array $linkIds,
    ) {
        parent::__construct();
    }

    public function handle(Scanner $scanner): void
    {
        $total = count($this->linkIds);

        foreach ($this->linkIds as $i => $linkId) {
            $link = Link::query()->find($linkId);

            if ($link instanceof Link && ! $link->isIgnored) {
                $scanner->checkLink($link);
            }

            $this->setProgress((int) round(($i + 1) / max(1, $total) * 100), t('Checked {num} of {total}', ['num' => $i + 1, 'total' => $total], category: 'appleseed'));
        }
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('Appleseed: Re-checking {count, plural, =1{# link} other{# links}}', ['count' => count($this->linkIds)], category: 'appleseed');
    }
}
