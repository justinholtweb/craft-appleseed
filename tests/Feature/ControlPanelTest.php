<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use JustinHoltWeb\Appleseed\Jobs\CheckLinks;
use JustinHoltWeb\Appleseed\Jobs\ScanEntry;
use JustinHoltWeb\Appleseed\Jobs\StartScan;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Tests\Support\SeedsLinks;
use JustinHoltWeb\Appleseed\Widgets\BrokenLinksWidget;

use function CraftCms\Cms\cp_url;

uses(SeedsLinks::class);

beforeEach(fn () => $this->seedLinks());

it('shows the dashboard, filters, a link and the CSV', function () {
    $this->actingAs($this->admin());

    $this->get(cp_url('appleseed'))->assertRedirect(cp_url('appleseed/dashboard'));
    $page = cpPage($this->get(cp_url('appleseed/dashboard'))->assertOk());
    expect($page)->toContain('gone.example.com/dead')->toContain('Harbour news');
    $page = cpPage($this->get(cp_url('appleseed/dashboard', ['status' => 'redirect']))->assertOk());
    expect($page)->toContain('moved.example.com/old')->not->toContain('gone.example.com/dead');
    $page = cpPage($this->get(cp_url('appleseed/dashboard', ['host' => 'gone.example.com', 'status' => '']))->assertOk());
    expect($page)->not->toContain('ok.example.com/fine');
    $page = cpPage($this->get(cp_url('appleseed/dashboard', ['entryId' => $this->entry->id, 'status' => '']))->assertOk());
    expect($page)->toContain('ok.example.com/fine');

    $dead = $this->link('https://gone.example.com/dead');
    $page = cpPage($this->get(cp_url('appleseed/dashboard/detail/'.$dead->id))->assertOk());
    expect($page)->toContain('Found in 1 location')->toContain('Re-check Link');
    $this->get(cp_url('appleseed/dashboard/detail/999999'))->assertNotFound();

    $csv = $this->get(cp_url('appleseed/dashboard/export-csv', ['status' => 'broken']));
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($csv->getContent())->toContain('https://gone.example.com/dead')->not->toContain('ok.example.com/fine');

    $this->getJson(cp_url('appleseed/dashboard/scan-progress'))->assertOk()->assertJson(['running' => false, 'finished' => true]);
    $page = cpPage($this->get(cp_url('appleseed/preview-email'))->assertOk());
    expect($page)->toContain('Appleseed Scan Report');
});

it('opens on the default status filter', function () {
    $this->settings()->defaultStatusFilter = 'broken';
    $this->actingAs($this->admin());

    $page = cpPage($this->get(cp_url('appleseed/dashboard'))->assertOk());
    expect($page)->toContain('gone.example.com/dead')->not->toContain('ok.example.com/fine');
});

it('queues scans, entry scans and re-checks, and ignores links, from the dashboard', function () {
    Bus::fake([StartScan::class, ScanEntry::class, CheckLinks::class]);
    $this->actingAs($this->admin());
    $dead = $this->link('https://gone.example.com/dead');

    $this->postJson(cp_url('appleseed/dashboard/run-scan'), ['type' => 'recheck'])->assertOk()->assertJson(['success' => true]);
    $this->postJson(cp_url('appleseed/dashboard/run-scan'), ['type' => 'full'])->assertStatus(409);
    $this->postJson(cp_url('appleseed/dashboard/run-scan'), ['type' => 'nope'])->assertStatus(400);
    $this->postJson(cp_url('appleseed/dashboard/cancel-scan'))->assertOk();
    $this->postJson(cp_url('appleseed/dashboard/run-scan'), ['sectionIds' => [$this->section()->id]])->assertOk();
    $this->postJson(cp_url('appleseed/dashboard/scan-entry'), ['entryId' => $this->entry->id])->assertOk();
    $this->postJson(cp_url('appleseed/dashboard/rescan-link'), ['linkId' => $dead->id])->assertOk();

    Bus::assertDispatched(StartScan::class, fn (StartScan $job) => $job->type === 'recheck');
    Bus::assertDispatched(StartScan::class, fn (StartScan $job) => $job->sectionIds === [$this->section()->id]);
    Bus::assertDispatched(ScanEntry::class);
    Bus::assertDispatched(CheckLinks::class, fn (CheckLinks $job) => $job->linkIds === [$dead->id]);

    $this->postJson(cp_url('appleseed/dashboard/ignore-link'), ['linkId' => $dead->id])->assertOk();
    expect($dead->refresh()->isIgnored)->toBeTrue();
    expect(cpPage($this->get(cp_url('appleseed/dashboard', ['status' => 'ignored']))))->toContain('gone.example.com/dead');

    $this->postJson(cp_url('appleseed/dashboard/unignore-link'), ['linkId' => $dead->id])->assertOk();
    expect($dead->refresh()->isIgnored)->toBeFalse();
});

it('sends admins to the settings screen, and shows them read-only to settings managers', function () {
    $this->actingAs($this->admin());
    $this->get(cp_url('appleseed/settings'))->assertRedirect(cp_url('settings/plugins/appleseed'));
    $this->get(cp_url('settings/plugins/appleseed'))->assertOk();

    $this->actingAs($this->userWith([Plugin::PERMISSION_SETTINGS], 'manager'));
    $this->get(cp_url('appleseed/settings'))->assertOk();
    $this->get(cp_url('appleseed/dashboard'))->assertForbidden();
});

it('keeps Appleseed from people without its permissions', function () {
    $this->actingAs($this->userWith([], 'nobody'));
    $this->get(cp_url('appleseed/dashboard'))->assertForbidden();
    $this->get(cp_url('appleseed/settings'))->assertForbidden();

    $this->actingAs($this->userWith([Plugin::PERMISSION_VIEW], 'viewer'));
    $page = cpPage($this->get(cp_url('appleseed/dashboard'))->assertOk());
    expect($page)->not->toContain('Run Full Scan');
    $this->postJson(cp_url('appleseed/dashboard/run-scan'), ['type' => 'full'])->assertForbidden();
    $this->postJson(cp_url('appleseed/dashboard/ignore-link'), ['linkId' => $this->link('https://gone.example.com/dead')->id])->assertForbidden();
});

it('lists only the screens a user can open, with the broken link badge', function () {
    $this->actingAs($this->userWith([Plugin::PERMISSION_VIEW], 'viewer'));
    $item = Plugin::getInstance()->getCpNavItem();

    expect($item->badgeCount)->toBe(1)
        ->and(array_map(fn ($sub) => $sub->label, $item->subnav))->toBe(['Dashboard']);

    $this->actingAs($this->userWith([], 'nobody'));
    expect(Plugin::getInstance()->getCpNavItem())->toBeNull();
});

it('adds the entry’s links to the entry editor’s sidebar', function () {
    $this->actingAs($this->admin());
    $metadata = $this->entry->getMetadata();

    expect($metadata)->toHaveKey('Links');
    $html = $metadata['Links']();

    expect($html)->toContain('1 broken link')
        ->and($html)->toContain('gone.example.com/dead')
        ->and($html)->toContain('Check this entry again');
});

it('shows the Broken Links widget', function () {
    $this->actingAs($this->admin());
    $widget = new BrokenLinksWidget(['limit' => 3]);

    expect($widget->props()['html'])->toContain('gone.example.com/dead')
        ->and(BrokenLinksWidget::isSelectable())->toBeTrue();
});
