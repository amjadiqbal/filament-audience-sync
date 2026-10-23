<?php

declare(strict_types=1);

use AmjadIqbal\FilamentAudienceSync\AudienceSyncPlugin;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Facades\AudienceSync;
use AmjadIqbal\FilamentAudienceSync\Jobs\SyncAudienceChunk;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource\ListUsers;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource\ViewUser;
use AmjadIqbal\FilamentAudienceSync\Tests\TestCase;
use AmjadIqbal\FilamentAudienceSync\Widgets\AudienceStatsWidget;
use AmjadIqbal\FilamentAudienceSync\Widgets\RecentCampaignsWidget;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

const MEMBERS = 'https://us21.api.mailchimp.com/3.0/lists/aud123/members';

beforeEach(function (): void {
    $this->admin = $this->makeUser('Admin User');
    $this->actingAs($this->admin);
    $this->ada = $this->makeUser();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function audiencePlugin(): AudienceSyncPlugin
{
    /** @var AudienceSyncPlugin */
    return Filament::getPanel('admin')->getPlugin(AudienceSyncPlugin::ID);
}

function rowFor($user, Status $status, array $extra = []): SyncedMember
{
    return SyncedMember::query()->create([
        'subscribable_type' => $user->getMorphClass(),
        'subscribable_id' => $user->getKey(),
        'driver' => 'mailchimp',
        'list_id' => 'aud123',
        'email' => $user->email,
        'status' => $status,
        'synced_at' => now(),
        ...$extra,
    ]);
}

it('shows each record\'s status from the local rows, without calling the provider', function (): void {
    Http::fake();
    rowFor($this->ada, Status::Subscribed);
    $grace = $this->makeUser('Grace Hopper');
    rowFor($grace, Status::Unsubscribed, ['last_error' => 'Mailchimp: something broke']);

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$this->ada, $grace, $this->admin])
        ->assertTableColumnFormattedStateSet('audienceMembership.status', 'Subscribed', $this->ada)
        ->assertTableColumnFormattedStateSet('audienceMembership.status', 'Unsubscribed', $grace)
        ->assertSee('Not synced');

    Http::assertNothingSent();
});

it('subscribes a record from the table action', function (): void {
    Http::fake([
        MEMBERS.'/'.md5('ada.lovelace@example.com').'*' => Http::response(TestCase::fixture('mailchimp/not-found'), 404),
        MEMBERS => Http::response(TestCase::fixture('mailchimp/member-subscribed')),
    ]);

    livewire(ListUsers::class)
        ->callAction(TestAction::make('audienceSubscribe')->table($this->ada))
        ->assertNotified('Subscribed');

    expect(AudienceSync::membership($this->ada)?->status)->toBe(Status::Subscribed);
});

it('disables subscribe for someone who unsubscribed and hides it for members', function (): void {
    rowFor($this->ada, Status::Unsubscribed);
    $bob = $this->makeUser('Bob Builder');
    rowFor($bob, Status::Subscribed);

    livewire(ListUsers::class)
        ->assertActionDisabled(TestAction::make('audienceSubscribe')->table($this->ada))
        ->assertActionHidden(TestAction::make('audienceSubscribe')->table($bob))
        ->assertActionHidden(TestAction::make('audienceUnsubscribe')->table($this->ada));
});

it('unsubscribes after confirmation', function (): void {
    rowFor($this->ada, Status::Subscribed);
    Http::fake([MEMBERS.'/'.md5('ada.lovelace@example.com') => Http::response(TestCase::fixture('mailchimp/member-unsubscribed'))]);

    livewire(ViewUser::class, ['record' => $this->ada->getKey()])
        ->callAction('audienceUnsubscribe')
        ->assertNotified('Unsubscribed');

    expect(AudienceSync::membership($this->ada)?->status)->toBe(Status::Unsubscribed);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['status'] === 'unsubscribed');
});

it('tells the admin why a sync failed, without the key', function (): void {
    Http::fake([MEMBERS.'/*' => Http::response(TestCase::fixture('mailchimp/api-key-invalid'), 401)]);

    livewire(ViewUser::class, ['record' => $this->ada->getKey()])
        ->callAction('audienceUpdate')
        ->assertNotified('Sync failed');

    expect(AudienceSync::membership($this->ada)?->last_error)->not->toContain(TestCase::MAILCHIMP_KEY);
});

it('edits tags with Mailchimp and hides the tag action for Brevo', function (): void {
    rowFor($this->ada, Status::Subscribed, ['tags' => ['trial']]);
    Http::fake([
        MEMBERS.'/'.md5('ada.lovelace@example.com').'/tags' => Http::response(null, 204),
        MEMBERS.'/'.md5('ada.lovelace@example.com').'*' => Http::response(TestCase::fixture('mailchimp/member-subscribed')),
    ]);

    livewire(ViewUser::class, ['record' => $this->ada->getKey()])
        ->callAction('audienceTags', ['add' => ['vip'], 'remove' => ['trial']])
        ->assertNotified('Synced');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/tags') && $r['tags'] === [['name' => 'vip', 'status' => 'active'], ['name' => 'trial', 'status' => 'inactive']]);

    $this->useDriver('brevo');
    livewire(ViewUser::class, ['record' => $this->ada->getKey()])->assertActionHidden('audienceTags');
});

it('queues bulk actions instead of calling the provider in the request', function (): void {
    Queue::fake();
    Http::fake();
    $bob = $this->makeUser('Bob Builder');

    livewire(ListUsers::class)
        ->selectTableRecords([$this->ada->getKey(), $bob->getKey()])
        ->callAction(TestAction::make('audienceBulkSubscribe')->table()->bulk())
        ->assertNotified('2 records queued');

    Queue::assertPushed(SyncAudienceChunk::class, fn (SyncAudienceChunk $job): bool => $job->ids === [$this->ada->id, $bob->id] && $job->operation === 'subscribe');
    Http::assertNothingSent();
});

it('shows the status, tags and last sync on the view page', function (): void {
    rowFor($this->ada, Status::Pending, ['tags' => ['customer', 'vip']]);

    livewire(ViewUser::class, ['record' => $this->ada->getKey()])
        ->assertSee('Pending confirmation')
        ->assertSee('Tags: customer, vip');
});

it('honours the manageAudience gate and authorizeUsing', function (): void {
    Gate::define('manageAudience', fn ($user, string $ability): bool => $ability === 'subscribe');

    livewire(ListUsers::class)
        ->assertActionVisible(TestAction::make('audienceSubscribe')->table($this->ada))
        ->assertActionHidden(TestAction::make('audienceUnsubscribe')->table($this->ada))
        ->assertActionHidden(TestAction::make('audienceUpdate')->table($this->ada));

    audiencePlugin()->authorizeUsing(fn (string $ability): bool => false);

    livewire(ListUsers::class)->assertActionHidden(TestAction::make('audienceSubscribe')->table($this->ada));
    expect(AudienceStatsWidget::canView())->toBeFalse();

    audiencePlugin()->authorizeUsing(null);
});

it('renders list stats and recent campaigns, cached', function (): void {
    Http::fake([
        'https://us21.api.mailchimp.com/3.0/lists/aud123?*' => Http::response(TestCase::fixture('mailchimp/list')),
        'https://us21.api.mailchimp.com/3.0/campaigns?*' => Http::response(TestCase::fixture('mailchimp/campaigns')),
    ]);

    livewire(AudienceStatsWidget::class)->assertSee('4,812')->assertSee('42.4%')->assertSee('Newsletter');
    livewire(AudienceStatsWidget::class)->assertSee('6.1%');
    livewire(RecentCampaignsWidget::class)->assertSee('October product news')->assertSee('September digest')->assertSee('42.4%');

    Http::assertSentCount(2);
});

it('shows a provider error on the widgets instead of failing the dashboard', function (): void {
    Http::fake(['*' => Http::response(TestCase::fixture('mailchimp/api-key-invalid'), 401)]);

    livewire(AudienceStatsWidget::class)
        ->assertSee('List stats unavailable')
        ->assertSee('API key may be invalid')
        ->assertDontSee(TestCase::MAILCHIMP_KEY);

    livewire(RecentCampaignsWidget::class)->assertSee('Campaigns unavailable');
});

it('registers the widgets on the panel and with Livewire by name', function (): void {
    Http::fake(['*' => Http::response(TestCase::fixture('mailchimp/api-key-invalid'), 401)]);

    expect(Filament::getPanel('admin')->getWidgets())->toContain(AudienceStatsWidget::class, RecentCampaignsWidget::class);

    livewire('amjadiqbal.filament-audience-sync.audience-stats-widget')->assertSee('List stats unavailable');
    livewire('amjadiqbal.filament-audience-sync.recent-campaigns-widget')->assertSee('Campaigns unavailable');
});
