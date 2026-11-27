<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Appleseed\Jobs\ScanEntry;
use JustinHoltWeb\Appleseed\Jobs\StartScan;
use JustinHoltWeb\Appleseed\Mcp\AppleseedTools;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Tests\Support\SeedsLinks;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

uses(SeedsLinks::class);

beforeEach(fn () => $this->seedLinks());

it('declares the same eleven tools as Appleseed 5.3', function () {
    $names = [];

    foreach ((new ReflectionClass(AppleseedTools::class))->getMethods() as $method) {
        foreach ($method->getAttributes(McpTool::class) as $attribute) {
            $names[] = $attribute->newInstance()->name;
        }
    }

    expect($names)->toBe([
        'appleseed_status', 'appleseed_links_list', 'appleseed_link_get', 'appleseed_entry_links', 'appleseed_scans_list',
        'appleseed_scan_run', 'appleseed_scan_cancel', 'appleseed_entry_scan', 'appleseed_link_recheck', 'appleseed_url_check', 'appleseed_link_ignore',
    ]);
});

it('reports status in the shared shape', function () {
    $status = $this->mcpAs($this->admin())->status();

    expect(array_keys($status))->toBe(['summary', 'latestScan', 'runningScan', 'worstHosts', 'worstEntries', 'schedule', 'dashboardUrl'])
        ->and($status['summary']['broken'])->toBe(1)
        ->and(array_keys($status['latestScan']))->toBe(['id', 'type', 'status', 'totalLinksFound', 'totalLinksChecked', 'brokenCount', 'newBrokenCount', 'redirectCount', 'workingCount', 'entryId', 'startedAt', 'completedAt'])
        ->and($status['runningScan'])->toBeNull()
        ->and($status['worstHosts'])->toBe([['host' => 'gone.example.com', 'broken' => 1]])
        ->and($status['worstEntries'][0])->toBe(['entryId' => $this->entry->id, 'siteId' => $this->entry->siteId, 'broken' => 1, 'title' => 'Harbour news'])
        ->and($status['schedule'])->toBe(['frequency' => 'manual', 'scanType' => 'full']);
});

it('lists and gets links, and an entry’s links', function () {
    $tools = $this->mcpAs($this->admin());

    $list = $tools->listLinks(status: 'broken');
    expect($list)->toMatchArray(['total' => 1, 'count' => 1, 'nextOffset' => null])
        ->and(array_keys($list['links'][0]))->toBe(['id', 'url', 'host', 'status', 'isBroken', 'statusCode', 'redirectUrl', 'redirectChain', 'errorMessage', 'isIgnored', 'dateIgnored', 'lastCheckedAt', 'firstBrokenAt', 'sourceCount', 'sources', 'cpUrl'])
        ->and($list['links'][0]['sources'][0])->toMatchArray(['sourceType' => 'database', 'entryTitle' => 'Harbour news', 'fieldHandle' => 'body']);

    expect($tools->listLinks(limit: 2)['nextOffset'])->toBe(2);

    $link = $tools->getLink(url: 'https://moved.example.com/old');
    expect($link)->toMatchArray(['status' => 'redirect', 'redirectChain' => ['https://moved.example.com/new'], 'ignoredBy' => null])
        ->and(fn () => $tools->getLink(linkId: 999999))->toThrow(ToolCallException::class);

    $entryLinks = $tools->entryLinks($this->entry->id, problemsOnly: true);
    expect($entryLinks['title'])->toBe('Harbour news')
        ->and(array_column($entryLinks['links'], 'url'))->toEqualCanonicalizing(['https://gone.example.com/dead', 'https://moved.example.com/old'])
        ->and($entryLinks['links'][0])->toHaveKeys(['fields', 'linkText']);

    expect($tools->listScans()['total'])->toBe(1);
});

it('queues scans, never runs them inline', function () {
    Bus::fake([StartScan::class, ScanEntry::class]);
    $tools = $this->mcpAs($this->admin());

    expect($tools->runScan('incremental'))->toMatchArray(['queued' => true, 'type' => 'incremental'])
        ->and($tools->runScan('full')['queued'])->toBeFalse()
        ->and($tools->cancelScan())->toBe(['cancelled' => 0])
        ->and($tools->scanEntry($this->entry->id))->toMatchArray(['queued' => true, 'entryId' => $this->entry->id])
        ->and(fn () => $tools->runScan('everything'))->toThrow(ToolCallException::class);

    Bus::assertDispatched(StartScan::class, fn (StartScan $job) => $job->type === 'incremental');
    Bus::assertDispatched(ScanEntry::class, fn (ScanEntry $job) => $job->entryId === $this->entry->id);
});

it('checks URLs and links now, and ignores links as the user', function () {
    // The dead link has come back. (Earlier stubs win, so start a fresh fake.)
    Http::swap(new Factory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('ok')]);
    $admin = $this->admin();
    $tools = $this->mcpAs($admin);
    $dead = $this->link('https://gone.example.com/dead');

    expect($tools->recheckLink($dead->id))->toMatchArray(['status' => 'working', 'firstBrokenAt' => null])
        ->and($tools->checkUrl('https://example.com/x'))->toBe(['url' => 'https://example.com/x', 'status' => 'working', 'isBroken' => false, 'statusCode' => 200, 'redirectUrl' => null, 'redirectChain' => [], 'errorMessage' => null])
        ->and($tools->checkUrl('http://169.254.169.254/')['status'])->toBe('ignored')
        ->and(fn () => $tools->checkUrl('ftp://example.com'))->toThrow(ToolCallException::class);

    expect($tools->ignoreLink($dead->id)['isIgnored'])->toBeTrue()
        ->and($dead->refresh()->ignoredById)->toBe($admin->id)
        ->and($tools->getLink($dead->id)['ignoredBy'])->toBe($admin->getName())
        ->and(fn () => $tools->recheckLink($dead->id))->toThrow(ToolCallException::class)
        ->and($tools->ignoreLink($dead->id, ignored: false)['status'])->toBe('unknown');
});

it('refuses users without Appleseed’s permissions', function () {
    $viewer = $this->mcpAs($this->userWith([Plugin::PERMISSION_VIEW], 'viewer'));

    expect($viewer->status()['summary']['total'])->toBe(3)
        ->and(fn () => $viewer->runScan())->toThrow(ToolCallException::class, 'You don’t have permission to do that.')
        ->and(fn () => $viewer->ignoreLink($this->link('https://gone.example.com/dead')->id))->toThrow(ToolCallException::class)
        // Viewing an entry's links needs the entry too: the Team group can't view the section.
        ->and(fn () => $viewer->entryLinks($this->entry->id))->toThrow(ToolCallException::class, 'Entry not found.');

    $nobody = $this->mcpAs($this->userWith([], 'nobody'));
    expect(fn () => $nobody->status())->toThrow(ToolCallException::class);
});
