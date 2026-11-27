<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use JustinHoltWeb\Appleseed\Jobs\ScanEntry;
use JustinHoltWeb\Appleseed\Jobs\SendScanWebhook;
use JustinHoltWeb\Appleseed\Jobs\StartScan;
use JustinHoltWeb\Appleseed\Mail\ScanReportMail;
use JustinHoltWeb\Appleseed\Models\Scan;
use JustinHoltWeb\Appleseed\Notifications\BrokenLinksFound;
use JustinHoltWeb\Appleseed\Notifications\BrokenLinksNotice;
use JustinHoltWeb\Appleseed\Notifications\Channels\SlackWebhookChannel;
use JustinHoltWeb\Appleseed\Plugin;
use JustinHoltWeb\Appleseed\Services\Scanner;
use JustinHoltWeb\Appleseed\Services\Schedules;
use JustinHoltWeb\Appleseed\Support\ScanType;
use JustinHoltWeb\Appleseed\Tests\Support\SeedsLinks;

uses(SeedsLinks::class);

it('emails each recipient and posts to Slack when the threshold is met', function () {
    Notification::fake();
    $this->settings()->notificationEmails = 'ops@example.com, dev@example.com';
    $this->settings()->slackWebhookUrl = 'https://hooks.slack.com/services/T/B/x';

    $this->seedLinks();

    Notification::assertSentOnDemand(BrokenLinksFound::class, fn ($n, array $channels, AnonymousNotifiable $to) => $channels === ['mail'] && $to->routes['mail'] === 'ops@example.com');
    Notification::assertSentOnDemand(BrokenLinksFound::class, fn ($n, array $channels, AnonymousNotifiable $to) => $channels === ['mail'] && $to->routes['mail'] === 'dev@example.com');
    Notification::assertSentOnDemand(BrokenLinksFound::class, fn ($n, array $channels, AnonymousNotifiable $to) => $channels === [SlackWebhookChannel::class]);
    Notification::assertSentOnDemandTimes(BrokenLinksFound::class, 3);
});

it('renders the report email and the Slack message', function () {
    Mail::fake();
    $this->seedLinks();
    $this->settings()->notificationEmails = 'ops@example.com';
    $notification = new BrokenLinksFound($this->scan);

    $mail = $notification->toMail(Notification::route('mail', 'ops@example.com'));
    expect($mail)->toBeInstanceOf(ScanReportMail::class)
        ->and($mail->subject)->toBe('Appleseed: 1 broken link found')
        ->and($mail->htmlBody)->toContain('https://gone.example.com/dead')
        ->and($mail->htmlBody)->toContain('Harbour news')
        ->and($mail->hasTo('ops@example.com'))->toBeTrue();

    $slack = $notification->toSlackWebhook(Notification::route(SlackWebhookChannel::class, 'https://hooks.slack.com/x'));
    expect($slack['text'])->toContain('*Appleseed:* 1 broken link found.')
        ->and($slack['text'])->toContain('gone.example.com/dead');
});

it('posts Slack messages through its webhook channel', function () {
    Http::fake(['https://hooks.slack.com/*' => Http::response('ok')]);
    $this->seedLinks();

    Notification::route(SlackWebhookChannel::class, 'https://hooks.slack.com/services/T/B/x')->notifyNow(new BrokenLinksFound($this->scan));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.slack.com/services/T/B/x' && str_contains((string) $request['text'], 'broken link'));
});

it('notifies only about newly broken links when asked, below the threshold never', function () {
    Notification::fake();
    $this->settings()->notificationEmails = 'ops@example.com';
    $this->settings()->notifyOnlyNew = true;
    $this->seedLinks();
    Notification::assertSentOnDemandTimes(BrokenLinksFound::class, 1);

    // Same broken link again: nothing new, so no notification.
    $this->travel(1)->hours();
    app(Scanner::class)->runScan(ScanType::DATABASE);
    Notification::assertSentOnDemandTimes(BrokenLinksFound::class, 1);

    $this->settings()->notifyOnlyNew = false;
    $this->settings()->notificationThreshold = 5;
    app(Scanner::class)->runScan(ScanType::DATABASE);
    Notification::assertSentOnDemandTimes(BrokenLinksFound::class, 1);
});

it('never notifies after a scan of one entry', function () {
    Notification::fake();
    Bus::fake([SendScanWebhook::class]);
    $this->settings()->notificationEmails = 'ops@example.com';
    $this->settings()->webhookUrl = 'https://hooks.example.com/appleseed';
    $this->seedLinks();
    Notification::assertSentOnDemandTimes(BrokenLinksFound::class, 1);

    app(Scanner::class)->runScan(ScanType::ENTRY, $this->entry->id);

    Notification::assertSentOnDemandTimes(BrokenLinksFound::class, 1);
    Bus::assertDispatchedTimes(SendScanWebhook::class, 1);
});

it('notifies people who can run scans in the control panel', function () {
    Notification::fake();
    $this->settings()->notifyCpUsers = true;
    $scanner = $this->userWith([Plugin::PERMISSION_VIEW, Plugin::PERMISSION_SCAN], 'scanner');

    $this->seedLinks();

    Notification::assertSentTo($scanner, BrokenLinksNotice::class);
});

it('queues a signed webhook after every scan, retried with backoff', function () {
    Bus::fake([SendScanWebhook::class]);
    $this->settings()->webhookUrl = 'https://hooks.example.com/appleseed';
    $this->settings()->webhookSecret = 's3cret';

    $this->seedLinks();

    Bus::assertDispatched(SendScanWebhook::class, function (SendScanWebhook $job) {
        expect(array_keys($job->payload))->toBe(['event', 'site', 'dashboardUrl', 'scan', 'summary', 'brokenLinks'])
            ->and($job->payload['event'])->toBe('scan.completed')
            ->and($job->payload['brokenLinks'][0])->toMatchArray(['url' => 'https://gone.example.com/dead', 'statusCode' => 404])
            ->and($job->payload['brokenLinks'][0])->toHaveKey('cpUrl')
            ->and($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([10, 60, 300, 900]);

        Http::fake(['https://hooks.example.com/*' => Http::response('ok')]);
        $job->handle($this->settings());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hooks.example.com/appleseed'
            && $request->header('X-Appleseed-Event')[0] === 'scan.completed'
            && $request->header('X-Appleseed-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 's3cret'));

        return true;
    });
});

it('sends no webhook when none is set', function () {
    Bus::fake([SendScanWebhook::class]);
    $this->seedLinks();
    Bus::assertNotDispatched(SendScanWebhook::class);
});

it('queues a scan of an entry when it’s saved, if asked', function () {
    Bus::fake([ScanEntry::class]);
    $entry = $this->createEntry('Quiet');
    Bus::assertNotDispatched(ScanEntry::class);

    $this->settings()->scanOnEntrySave = true;
    $this->updateEntry($entry, ['body' => 'changed']);

    Bus::assertDispatched(ScanEntry::class, fn (ScanEntry $job) => $job->entryId === $entry->id);
});

it('schedules scans from the last full scan, never cold-starting', function () {
    Bus::fake([StartScan::class]);
    $schedules = app(Schedules::class);
    $this->settings()->scanFrequency = 'daily';
    $this->settings()->scheduledScanType = 'incremental';

    expect($schedules->runIfDue())->toBeFalse();

    Scan::query()->create(['status' => Scan::STATUS_COMPLETED, 'type' => ScanType::FULL, 'startedAt' => now(), 'completedAt' => now()]);
    // An entry scan doesn't push the schedule back.
    $this->travel(25)->hours();
    Scan::query()->create(['status' => Scan::STATUS_COMPLETED, 'type' => ScanType::ENTRY, 'startedAt' => now(), 'completedAt' => now()]);

    expect($schedules->isDue())->toBeTrue()
        ->and($schedules->runIfDue())->toBeTrue()
        ->and($schedules->runIfDue())->toBeFalse();

    Bus::assertDispatchedTimes(StartScan::class, 1);
    Bus::assertDispatched(StartScan::class, fn (StartScan $job) => $job->type === ScanType::INCREMENTAL);
});

it('registers the scheduled scan and pruning on Laravel’s scheduler', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description);

    expect($events)->toContain('appleseed:scan:scheduled')
        ->and($events)->toContain('appleseed:prune-scans');
});

it('runs from the console, under the Craft 5 names too', function () {
    $this->seedLinks();

    $this->artisan('appleseed:scan', ['--type' => 'database'])->assertSuccessful();
    $this->artisan('craft:appleseed/scan/recheck-broken')->assertSuccessful();
    expect(Scan::query()->newestFirst()->first()->type)->toBe(ScanType::RECHECK);

    $this->artisan('appleseed:scan:status')->assertSuccessful();
    $this->artisan('appleseed:scan:entry', ['--entry-id' => $this->entry->id])->assertSuccessful();
    $this->artisan('appleseed:links:export', ['--status' => 'broken'])->expectsOutputToContain('https://gone.example.com/dead')->assertSuccessful();
    $this->artisan('appleseed:links:recheck', ['linkId' => $this->link('https://gone.example.com/dead')->id])->assertFailed();
    $this->artisan('appleseed:check-url', ['url' => 'https://ok.example.com/x'])->assertSuccessful();
    $this->artisan('craft:appleseed/check-url', ['url' => 'https://gone.example.com/x'])->assertFailed();
    $this->artisan('appleseed:scan:cancel')->expectsOutput('No scan was running.')->assertSuccessful();
    $this->artisan('appleseed:scan:scheduled')->assertSuccessful();
    $this->artisan('appleseed:scan', ['--type' => 'nonsense'])->assertExitCode(2);

    Mail::fake();
    $this->artisan('appleseed:scan:email')->expectsOutputToContain('Appleseed Scan Report')->assertSuccessful();
    $this->artisan('craft:appleseed/scan/test-email', ['--to' => 'me@example.com'])->assertSuccessful();
    Mail::assertSent(ScanReportMail::class, fn (ScanReportMail $mail) => $mail->hasTo('me@example.com') && str_starts_with((string) $mail->subject, '[TEST]'));
});
