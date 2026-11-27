<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Services\Scanner;

#[Signature('appleseed:links:recheck {linkId : The link’s ID}')]
#[Description('Checks one known link again now. Exits non-zero when it’s broken.')]
#[Aliases(['appleseed/links/recheck'])]
class LinksRecheckCommand extends Command
{
    use CraftCommand;

    public function handle(Scanner $scanner): int
    {
        $link = Link::query()->find((int) $this->argument('linkId'));

        if (! $link instanceof Link) {
            $this->components->error('Link not found.');

            return self::INVALID;
        }

        $result = $scanner->checkLink($link);
        $this->line($link->url);
        $this->line('  Status: '.$result->status.($result->statusCode !== null ? " ({$result->statusCode})" : ''));

        return $result->isBroken() ? self::FAILURE : self::SUCCESS;
    }
}
