<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Tests\Support;

use CraftCms\Cms\Entry\Elements\Entry;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\ScanType;

/**
 * One entry with a working link, a broken one and a redirect, scanned.
 */
trait SeedsLinks
{
    protected Entry $entry;

    protected Scan $scan;

    protected function seedLinks(): void
    {
        $this->settings()->spiderEnabled = false;
        $this->settings()->rateLimitPerSecond = 100;

        Http::fake([
            'https://gone.example.com/*' => Http::response('', 404),
            'https://moved.example.com/old' => Http::response('', 301, ['Location' => 'https://moved.example.com/new']),
            '*' => Http::response('ok'),
        ]);

        $this->entry = $this->createEntry('Harbour news', [
            'body' => '<a href="https://ok.example.com/fine">Fine</a> <a href="https://gone.example.com/dead">Dead</a>',
            'cta' => 'https://moved.example.com/old',
        ]);

        $this->scan = app(Scanner::class)->runScan(ScanType::DATABASE);
    }

    protected function link(string $url): Link
    {
        return Link::query()->where('url', $url)->sole();
    }
}
