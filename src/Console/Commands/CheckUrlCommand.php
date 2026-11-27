<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JustinHoltWeb\Appleseed\Services\LinkChecker;

#[Signature('appleseed:check-url {url : An http:// or https:// URL} {--json : Print the result as JSON}')]
#[Description('Checks one URL and reports its status, storing nothing. Exits non-zero when it’s broken.')]
#[Aliases(['appleseed/check-url'])]
class CheckUrlCommand extends Command
{
    use CraftCommand;

    public function handle(LinkChecker $checker): int
    {
        $url = trim((string) $this->argument('url'));
        $result = $checker->checkUrl($url);

        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result->isBroken() ? self::FAILURE : self::SUCCESS;
        }

        $this->line("Checking: {$url}");
        $this->components->twoColumnDetail('Status', $result->status);

        if ($result->statusCode !== null) {
            $this->components->twoColumnDetail('HTTP code', (string) $result->statusCode);
        }

        if ($result->redirectUrl !== null) {
            $this->components->twoColumnDetail('Redirects to', $result->redirectUrl);
        }

        foreach ($result->redirectChain ?? [] as $hop) {
            $this->line("    -> {$hop}");
        }

        if ($result->errorMessage !== null) {
            $this->components->twoColumnDetail('Error', $result->errorMessage);
        }

        return $result->isBroken() ? self::FAILURE : self::SUCCESS;
    }
}
