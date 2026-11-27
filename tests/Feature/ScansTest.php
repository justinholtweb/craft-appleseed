<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Appleseed\Events\DefiningFieldLinks;
use JustinHoltWeb\Appleseed\Events\LinkBroke;
use JustinHoltWeb\Appleseed\Events\ScanCompleted;
use JustinHoltWeb\Appleseed\Jobs\CheckLinkChunk;
use JustinHoltWeb\Appleseed\Jobs\StartScan;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\LinkSource;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Services\ScanAlreadyRunning;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Support\LinkStatus;
use JustinHoltWeb\Appleseed\Support\ScanType;

beforeEach(function () {
    $this->settings()->spiderEnabled = false;
    $this->settings()->rateLimitPerSecond = 100;
});

/**
 * Fakes the web: ok.example.com answers 200, gone.example.com 404, down.example.com 500.
 * Stubs registered first win, so a test's own go in `$first`.
 *
 * @param  array<string, mixed>  $first
 */
function fakeWeb(array $first = []): void
{
    Http::fake($first + [
        'https://ok.example.com/*' => Http::response('ok'),
        'https://gone.example.com/*' => Http::response('nope', 404),
        'https://down.example.com/*' => Http::response('oops', 500),
        '*' => Http::response('ok'),
    ]);
}

function linkFor(string $url): ?Link
{
    return Link::query()->where('url', $url)->first();
}

it('finds links in fields and nested entries, records them against the owner and checks them', function () {
    fakeWeb();
    $entry = $this->createEntry('Article', [
        'body' => '<p><a href="https://ok.example.com/a">Fine</a> and <a href="https://gone.example.com/b#top">gone</a> and <a href="mailto:x@example.com">mail</a> and <a href="/relative">rel</a></p>',
        'cta' => 'https://down.example.com/c',
        'blocks' => $this->blocks([['target' => 'https://ok.example.com/block', 'text' => '<a href="https://user:secret@ok.example.com/creds">creds</a>']]),
    ]);

    $scan = app(Scanner::class)->runScan(ScanType::DATABASE);

    expect($scan->status)->toBe(Scan::STATUS_COMPLETED)
        ->and($scan->totalLinksFound)->toBe(6)
        ->and($scan->totalLinksChecked)->toBe(6)
        ->and($scan->brokenCount)->toBe(2)
        ->and($scan->newBrokenCount)->toBe(2)
        ->and($scan->workingCount)->toBe(4);

    expect(linkFor('https://gone.example.com/b')->status)->toBe(LinkStatus::BROKEN)
        ->and(linkFor('https://gone.example.com/b')->firstBrokenAt)->not->toBeNull()
        ->and(linkFor('https://down.example.com/c')->status)->toBe(LinkStatus::SERVER_ERROR)
        ->and(linkFor($this->siteUrl().'/relative'))->not->toBeNull()
        // Credentials are never stored
        ->and(linkFor('https://ok.example.com/creds'))->not->toBeNull()
        ->and(Link::query()->where('url', 'like', '%secret%')->exists())->toBeFalse();

    // The Matrix block's links belong to the entry an editor can open, with the field path
    $nested = LinkSource::query()->where('linkId', linkFor('https://ok.example.com/block')->id)->sole();
    expect($nested->entryId)->toBe($entry->id)
        ->and($nested->fieldHandle)->toBe('blocks.target')
        ->and(LinkSource::query()->where('linkId', linkFor('https://ok.example.com/creds')->id)->value('fieldHandle'))->toBe('blocks.text');
});

it('replaces sources instead of piling them up, and prunes links found nowhere', function () {
    fakeWeb();
    $entry = $this->createEntry('Article', [
        'body' => '<a href="https://ok.example.com/keep">keep</a> <a href="https://gone.example.com/remove">remove</a>',
    ]);
    $scanner = app(Scanner::class);

    $scanner->runScan(ScanType::DATABASE);
    $scanner->runScan(ScanType::DATABASE);

    $keep = linkFor('https://ok.example.com/keep');
    expect(LinkSource::query()->where('linkId', $keep->id)->count())->toBe(1);

    // An ignored link survives its last source going away; an ordinary one doesn't
    $this->createEntry('Other', ['body' => '<a href="https://ok.example.com/ignored">x</a>']);
    $scanner->runScan(ScanType::DATABASE);
    $scanner->ignoreLink(linkFor('https://ok.example.com/ignored'), $this->admin()->id);

    $this->updateEntry($entry, ['body' => '<a href="https://ok.example.com/keep">keep</a>']);
    Link::query()->where('url', 'https://ok.example.com/ignored')->first();
    $other = Entry::find()->title('Other')->one();
    $this->updateEntry($other, ['body' => 'no links now']);

    $scanner->runScan(ScanType::DATABASE);

    expect(linkFor('https://gone.example.com/remove'))->toBeNull()
        ->and(linkFor('https://ok.example.com/ignored'))->not->toBeNull()
        ->and(linkFor('https://ok.example.com/ignored')->isIgnored)->toBeTrue()
        ->and(LinkSource::query()->where('linkId', $keep->id)->count())->toBe(1);
});

it('counts a link as newly broken once, and tells LinkBroke once', function () {
    fakeWeb();
    Event::fake([LinkBroke::class]);
    $this->createEntry('Article', ['body' => '<a href="https://gone.example.com/x">x</a>']);
    $scanner = app(Scanner::class);

    $first = $scanner->runScan(ScanType::DATABASE);
    $this->travel(5)->minutes();
    $second = $scanner->runScan(ScanType::DATABASE);

    expect($first->newBrokenCount)->toBe(1)
        ->and($second->newBrokenCount)->toBe(0)
        ->and($second->brokenCount)->toBe(1);

    Event::assertDispatchedTimes(LinkBroke::class, 1);
});

it('dispatches ScanCompleted with final tallies', function () {
    fakeWeb();
    Event::fake([ScanCompleted::class]);
    $this->createEntry('Article', ['body' => '<a href="https://gone.example.com/x">x</a>']);

    app(Scanner::class)->runScan(ScanType::DATABASE);

    Event::assertDispatched(ScanCompleted::class, fn (ScanCompleted $e) => $e->scan->brokenCount === 1 && $e->scan->status === Scan::STATUS_COMPLETED);
});

it('re-checks only broken links, reading no content', function () {
    fakeWeb();
    $this->createEntry('Article', ['body' => '<a href="https://gone.example.com/x">x</a> <a href="https://ok.example.com/y">y</a>']);
    $scanner = app(Scanner::class);
    $scanner->runScan(ScanType::DATABASE);

    $recheck = $scanner->runScan(ScanType::RECHECK);

    expect($recheck->type)->toBe(ScanType::RECHECK)
        ->and($recheck->totalLinksFound)->toBe(1)
        ->and($recheck->brokenCount)->toBe(1);
});

it('reads every entry on the first incremental scan, then only changed ones', function () {
    fakeWeb();
    $this->createEntry('Article', ['body' => '<a href="https://ok.example.com/one">x</a>']);
    $scanner = app(Scanner::class);
    $this->travel(1)->minutes();

    $first = $scanner->runScan(ScanType::INCREMENTAL);
    expect($first->type)->toBe(ScanType::DATABASE)->and($first->totalLinksFound)->toBe(1);

    $this->travel(1)->hours();
    $this->createEntry('Newer', ['body' => '<a href="https://ok.example.com/two">x</a>']);
    $second = $scanner->runScan(ScanType::INCREMENTAL);

    expect($second->type)->toBe(ScanType::INCREMENTAL)
        ->and($second->totalLinksFound)->toBe(1)
        ->and(linkFor('https://ok.example.com/one'))->not->toBeNull();
});

it('scans one entry and replaces only its sources', function () {
    fakeWeb();
    $entry = $this->createEntry('Article', ['body' => '<a href="https://ok.example.com/one">x</a>']);
    $this->createEntry('Other', ['body' => '<a href="https://ok.example.com/other">x</a>']);
    $scanner = app(Scanner::class);
    $scanner->runScan(ScanType::DATABASE);

    $this->updateEntry($entry, ['body' => '<a href="https://ok.example.com/two">x</a>']);
    $scan = $scanner->runScan(ScanType::ENTRY, $entry->id);

    expect($scan->type)->toBe(ScanType::ENTRY)
        ->and($scan->entryId)->toBe($entry->id)
        ->and(linkFor('https://ok.example.com/one'))->toBeNull()
        ->and(linkFor('https://ok.example.com/two'))->not->toBeNull()
        ->and(linkFor('https://ok.example.com/other'))->not->toBeNull();
});

it('skips excluded fields, and takes links from DefiningFieldLinks', function () {
    fakeWeb();
    $this->settings()->excludedFields = "cta\n";

    Event::listen(DefiningFieldLinks::class, function (DefiningFieldLinks $event) {
        if ($event->field->handle === 'body') {
            $event->links[] = ['url' => 'https://ok.example.com/from-event', 'linkText' => 'Event'];
            $event->skipDefault = true;
        }
    });

    $this->createEntry('Article', ['body' => '<a href="https://ok.example.com/skipped">x</a>', 'cta' => 'https://ok.example.com/excluded']);
    app(Scanner::class)->runScan(ScanType::DATABASE);

    expect(linkFor('https://ok.example.com/from-event'))->not->toBeNull()
        ->and(LinkSource::query()->where('linkText', 'Event')->exists())->toBeTrue()
        ->and(linkFor('https://ok.example.com/skipped'))->toBeNull()
        ->and(linkFor('https://ok.example.com/excluded'))->toBeNull();
});

it('crawls the site on a full scan, recording nofollow links but not following them', function () {
    $this->settings()->spiderEnabled = true;
    $base = $this->siteUrl();

    fakeWeb([
        $base => Http::response('<a href="/about">About</a> <a href="/private" rel="nofollow">P</a> <a href="https://gone.example.com/z">Z</a>', 200, ['Content-Type' => 'text/html']),
        $base.'/about' => Http::response('<a href="https://ok.example.com/from-about">A</a>', 200, ['Content-Type' => 'text/html']),
        $base.'/private' => Http::response('<a href="https://ok.example.com/never">N</a>', 200, ['Content-Type' => 'text/html']),
    ]);

    app(Scanner::class)->runScan(ScanType::FULL);

    expect(linkFor('https://ok.example.com/from-about'))->not->toBeNull()
        ->and(linkFor($base.'/private'))->not->toBeNull()
        ->and(linkFor('https://ok.example.com/never'))->toBeNull()
        ->and(LinkSource::query()->where('sourceType', LinkSource::TYPE_SPIDER)->where('sourceUrl', $base.'/about')->exists())->toBeTrue()
        ->and(linkFor('https://gone.example.com/z')->status)->toBe(LinkStatus::BROKEN);
});

it('queues a scan as a Bus batch of chunks, finalised by the batch', function () {
    fakeWeb();
    $this->settings()->scanBatchSize = 2;
    $this->createEntry('Article', ['body' => collect(range(1, 5))->map(fn ($i) => "<a href=\"https://ok.example.com/{$i}\">{$i}</a>")->implode(' ')]);

    expect(app(Scanner::class)->queueScan(ScanType::DATABASE))->toBeTrue();

    // The sync queue ran it all: discovery, three chunks, then the batch's `finally`.
    $scan = Scan::query()->sole();
    expect($scan->status)->toBe(Scan::STATUS_COMPLETED)
        ->and($scan->totalLinksChecked)->toBe(5)
        ->and(DB::table('job_batches')->where('name', "Appleseed scan #{$scan->id}")->value('total_jobs'))->toBe(3);

    // The lock was released, so another scan can start.
    expect(app(Scanner::class)->queueScan(ScanType::RECHECK))->toBeTrue();
});

it('dispatches StartScan and holds the scan lock until the scan finishes', function () {
    fakeWeb();
    Bus::fake([StartScan::class]);
    $scanner = app(Scanner::class);

    expect($scanner->queueScan(ScanType::FULL))->toBeTrue()
        ->and($scanner->queueScan(ScanType::FULL))->toBeFalse()
        ->and($scanner->isQueued())->toBeTrue()
        ->and(fn () => $scanner->runScan(ScanType::DATABASE))->toThrow(ScanAlreadyRunning::class);

    Bus::assertDispatchedTimes(StartScan::class, 1);
    Bus::assertDispatched(StartScan::class, fn (StartScan $job) => $job->type === ScanType::FULL && $job->lockOwner !== null);

    // Cancelling frees it.
    $scanner->cancelRunningScans();
    expect($scanner->queueScan(ScanType::FULL))->toBeTrue();
});

it('cancels a running scan: its batch skips the rest', function () {
    fakeWeb();
    $this->createEntry('Article', ['body' => '<a href="https://ok.example.com/1">1</a>']);
    $scanner = app(Scanner::class);
    $scan = $scanner->startScan(ScanType::DATABASE);

    expect($scanner->cancelRunningScans())->toBe(1);

    (new CheckLinkChunk($scan->id, $scanner->scanLinkIds($scan->id)))->handle($scanner);
    $scanner->finishBatchedScan($scan->id, null);

    expect($scan->refresh()->status)->toBe(Scan::STATUS_CANCELLED)
        ->and(linkFor('https://ok.example.com/1')->status)->toBe(LinkStatus::UNKNOWN);
});

it('fails scans abandoned for two hours', function () {
    fakeWeb();
    $scan = Scan::query()->create(['status' => Scan::STATUS_RUNNING, 'type' => ScanType::FULL, 'startedAt' => now()]);
    $this->travel(3)->hours();

    app(Scanner::class)->failAbandonedScans();

    expect($scan->refresh()->status)->toBe(Scan::STATUS_FAILED);
});

it('prunes old finished scans with model:prune, keeping links', function () {
    fakeWeb();
    $this->createEntry('Article', ['body' => '<a href="https://ok.example.com/1">1</a>']);
    $old = app(Scanner::class)->runScan(ScanType::DATABASE);
    $this->travel(100)->days();
    $recent = app(Scanner::class)->runScan(ScanType::DATABASE);

    $this->artisan('model:prune', ['--model' => [Scan::class]])->assertSuccessful();

    expect(Scan::query()->find($old->id))->toBeNull()
        ->and(Scan::query()->find($recent->id))->not->toBeNull()
        ->and(linkFor('https://ok.example.com/1'))->not->toBeNull();
});
