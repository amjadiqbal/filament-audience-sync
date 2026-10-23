<?php

declare(strict_types=1);

use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\ListStats;
use AmjadIqbal\FilamentAudienceSync\Data\Member;
use AmjadIqbal\FilamentAudienceSync\Drivers\HttpDriver;
use AmjadIqbal\FilamentAudienceSync\Enums\Outcome;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Facades\AudienceSync;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use AmjadIqbal\FilamentAudienceSync\Tests\TestCase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function mcMember(string $email = 'ada.lovelace@example.com'): string
{
    return 'https://us21.api.mailchimp.com/3.0/lists/aud123/members/'.md5($email);
}

beforeEach(function (): void {
    $this->ada = $this->makeUser();
});

it('subscribes a new contact and stores the status locally', function (): void {
    Http::fake([
        mcMember().'*' => Http::response(TestCase::fixture('mailchimp/not-found'), 404),
        'https://us21.api.mailchimp.com/3.0/lists/aud123/members' => Http::response(TestCase::fixture('mailchimp/member-subscribed')),
    ]);

    $result = AudienceSync::subscribe($this->ada);
    $row = AudienceSync::membership($this->ada);

    expect($result->outcome)->toBe(Outcome::Subscribed)
        ->and($row?->status)->toBe(Status::Subscribed)
        ->and($row?->email)->toBe('ada.lovelace@example.com')
        ->and($row?->tags)->toBe(['customer'])
        ->and($row?->synced_at)->not->toBeNull()
        ->and($this->ada->audienceMembership()->first()?->is($row))->toBeTrue();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['merge_fields'] === ['FNAME' => 'Ada', 'LNAME' => 'Lovelace']);
});

it('never resubscribes someone who unsubscribed in the provider', function (string $fixture): void {
    Http::fake([mcMember().'*' => Http::response(TestCase::fixture("mailchimp/{$fixture}"))]);

    $result = AudienceSync::subscribe($this->ada);

    expect($result->outcome)->toBe(Outcome::SkippedUnsubscribed);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
})->with(['member-unsubscribed', 'member-cleaned', 'member-archived']);

it('never resubscribes someone unsubscribed here, without even asking the provider', function (): void {
    Http::fake([mcMember() => Http::response(TestCase::fixture('mailchimp/member-unsubscribed'))]);
    AudienceSync::unsubscribe($this->ada);

    Http::fake();
    $result = AudienceSync::subscribe($this->ada);

    expect($result->outcome)->toBe(Outcome::SkippedUnsubscribed);
    Http::assertNothingSent();
});

it('blocks the same address on another record too', function (): void {
    Http::fake([mcMember() => Http::response(TestCase::fixture('mailchimp/not-found'), 404)]);
    expect(AudienceSync::unsubscribe($this->ada)->outcome)->toBe(Outcome::Unsubscribed);
    expect(AudienceSync::membership($this->ada)?->status)->toBe(Status::Unsubscribed);

    $twin = $this->makeUser('Ada Twin', 'ADA.LOVELACE@example.com');
    Http::fake();

    expect(AudienceSync::subscribe($twin)->outcome)->toBe(Outcome::SkippedUnsubscribed);
    Http::assertNothingSent();
});

it('keeps a local unsubscribe when the provider forgets the contact, and lifts it only when the provider says subscribed', function (): void {
    Http::fake([mcMember().'*' => Http::sequence()
        ->push(TestCase::fixture('mailchimp/member-unsubscribed'))
        ->push(TestCase::fixture('mailchimp/not-found'), 404)
        // They re-joined through the provider's own signup form.
        ->push(TestCase::fixture('mailchimp/member-subscribed'))]);

    AudienceSync::unsubscribe($this->ada);
    AudienceSync::refresh($this->ada);
    expect(AudienceSync::membership($this->ada)?->status)->toBe(Status::Unsubscribed);

    AudienceSync::refresh($this->ada);
    expect(AudienceSync::membership($this->ada)?->status)->toBe(Status::Subscribed);
});

it('updates an existing member instead of subscribing again', function (): void {
    Http::fake([mcMember().'*' => Http::response(TestCase::fixture('mailchimp/member-pending'))]);

    expect(AudienceSync::subscribe($this->ada)->outcome)->toBe(Outcome::Updated);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && ! isset($r['status']));
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('only updates existing, still-subscribed members', function (string $fixture, int $code, Outcome $outcome): void {
    Http::fake([mcMember().'*' => Http::response(TestCase::fixture("mailchimp/{$fixture}"), $code)]);

    expect(AudienceSync::update($this->ada)->outcome)->toBe($outcome);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
})->with([
    ['not-found', 404, Outcome::SkippedNotMember],
    ['member-unsubscribed', 200, Outcome::SkippedUnsubscribed],
]);

it('records a failure with a redacted message and keeps the last known status', function (): void {
    Http::fake([mcMember().'*' => Http::sequence()
        ->push(TestCase::fixture('mailchimp/member-subscribed'))
        ->push(TestCase::fixture('mailchimp/api-key-invalid'), 401)]);
    AudienceSync::refresh($this->ada);

    $result = AudienceSync::refresh($this->ada);
    $row = AudienceSync::membership($this->ada);

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->error)->not->toContain(TestCase::MAILCHIMP_KEY)
        ->and($row?->status)->toBe(Status::Subscribed)
        ->and($row?->last_error)->toContain('[redacted]')
        ->and($row?->last_error)->not->toContain(TestCase::MAILCHIMP_KEY);
});

it('skips records without a valid email', function (): void {
    Http::fake();
    $nobody = $this->makeUser('Nobody', 'not-an-email');

    expect(AudienceSync::subscribe($nobody)->outcome)->toBe(Outcome::SkippedNoEmail);
    Http::assertNothingSent();
});

it('keeps one status row per driver and list', function (): void {
    Http::fake([
        mcMember().'*' => Http::response(TestCase::fixture('mailchimp/member-subscribed')),
        'https://api.brevo.com/v3/contacts/*' => Http::response(TestCase::fixture('brevo/contact-blocklisted')),
    ]);

    AudienceSync::refresh($this->ada);
    AudienceSync::refresh($this->ada, 'brevo');

    expect(SyncedMember::query()->count())->toBe(2)
        ->and(AudienceSync::membership($this->ada, 'brevo')?->status)->toBe(Status::Unsubscribed)
        ->and(AudienceSync::membership($this->ada)?->status)->toBe(Status::Subscribed);
});

it('runs the same consent rules for a driver registered with extend()', function (): void {
    config()->set('audience-sync.drivers.mailerlite', ['api_key' => 'ml-key', 'list' => 'group-1']);
    AudienceSync::extend('mailerlite', fn () => new class('mailerlite', config('audience-sync.drivers.mailerlite')) extends HttpDriver
    {
        public static array $calls = [];

        public function supports(string $feature): bool
        {
            return false;
        }

        protected function baseUrl(): string
        {
            return 'https://connect.mailerlite.test/api';
        }

        protected function authenticate(PendingRequest $request, string $apiKey): PendingRequest
        {
            return $request->withToken($apiKey);
        }

        protected function errorMessage(Response $response): string
        {
            return (string) $response->json('message');
        }

        public function member(string $email): Member
        {
            return new Member($email, Status::Unsubscribed);
        }

        public function subscribe(Contact $contact, Member $current): Member
        {
            throw new RuntimeException('must not be called');
        }

        public function update(Contact $contact): Member
        {
            throw new RuntimeException('must not be called');
        }

        public function unsubscribe(string $email): Member
        {
            return new Member($email, Status::Unsubscribed);
        }

        public function tag(string $email, array $add, array $remove = []): void {}

        public function stats(): ListStats
        {
            return new ListStats('Group', 3);
        }

        public function campaigns(int $limit = 5): array
        {
            return [];
        }

        public function lists(): array
        {
            return [];
        }
    });

    expect(AudienceSync::subscribe($this->ada, 'mailerlite')->outcome)->toBe(Outcome::SkippedUnsubscribed)
        ->and(AudienceSync::stats('mailerlite')->members)->toBe(3);
});
