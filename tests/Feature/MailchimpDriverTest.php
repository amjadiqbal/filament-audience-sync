<?php

declare(strict_types=1);

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\Member;
use AmjadIqbal\FilamentAudienceSync\Drivers\MailchimpDriver;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\NotConfiguredException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\RateLimitedException;
use AmjadIqbal\FilamentAudienceSync\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const MC = 'https://us21.api.mailchimp.com/3.0/';

function mc(): MailchimpDriver
{
    /** @var MailchimpDriver */
    return app(AudienceSyncManager::class)->driver('mailchimp');
}

function memberUrl(string $email = 'ada.lovelace@example.com'): string
{
    return MC.'lists/aud123/members/'.md5($email);
}

it('reads the data centre from the key and authenticates with basic auth', function (): void {
    Http::fake([memberUrl().'*' => Http::response(TestCase::fixture('mailchimp/member-subscribed'))]);

    $member = mc()->member('Ada.Lovelace@Example.com ');

    expect($member->status)->toBe(Status::Subscribed)
        ->and($member->tags)->toBe(['customer'])
        ->and($member->providerId)->toBe(md5('ada.lovelace@example.com'));

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), memberUrl().'?')
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('audience-sync:'.TestCase::MAILCHIMP_KEY)));
});

it('maps every Mailchimp status', function (string $fixture, Status $status): void {
    Http::fake([memberUrl().'*' => Http::response(TestCase::fixture("mailchimp/{$fixture}"))]);

    expect(mc()->member('ada.lovelace@example.com')->status)->toBe($status);
})->with([
    ['member-subscribed', Status::Subscribed],
    ['member-pending', Status::Pending],
    ['member-unsubscribed', Status::Unsubscribed],
    ['member-cleaned', Status::Cleaned],
    ['member-archived', Status::Archived],
    ['member-transactional', Status::NotSubscribed],
]);

it('treats a 404 as not subscribed', function (): void {
    Http::fake([memberUrl().'*' => Http::response(TestCase::fixture('mailchimp/not-found'), 404)]);

    expect(mc()->member('ada.lovelace@example.com')->status)->toBe(Status::NotSubscribed);
});

it('subscribes a new contact with one POST that cannot change an existing member', function (): void {
    Http::fake([MC.'lists/aud123/members' => Http::response(TestCase::fixture('mailchimp/member-subscribed'))]);

    $member = mc()->subscribe(new Contact('ada.lovelace@example.com', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'COMPANY' => 'Analytical', 'phone' => null], ['customer']), Member::absent('ada.lovelace@example.com'));

    expect($member->status)->toBe(Status::Subscribed);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->data() === [
        'email_address' => 'ada.lovelace@example.com',
        'status' => 'subscribed',
        'merge_fields' => ['FNAME' => 'Ada', 'LNAME' => 'Lovelace', 'COMPANY' => 'Analytical'],
        'tags' => ['customer'],
    ]);
});

it('subscribes as pending with double opt-in', function (): void {
    config()->set('audience-sync.drivers.mailchimp.double_opt_in', true);
    app(AudienceSyncManager::class)->forgetDrivers();
    Http::fake([MC.'lists/aud123/members' => Http::response(TestCase::fixture('mailchimp/member-pending'))]);

    expect(mc()->subscribe(new Contact('ada.lovelace@example.com'), Member::absent('ada.lovelace@example.com'))->status)->toBe(Status::Pending);
    Http::assertSent(fn (Request $r): bool => $r['status'] === 'pending' && ! isset($r['merge_fields']));
});

it('opts in a transactional contact with PUT', function (): void {
    Http::fake([
        memberUrl() => Http::response(TestCase::fixture('mailchimp/member-subscribed')),
        memberUrl().'/tags' => Http::response(null, 204),
    ]);

    $current = new Member('ada.lovelace@example.com', Status::NotSubscribed, null, [], 'transactional');
    $member = mc()->subscribe(new Contact('ada.lovelace@example.com', [], ['vip']), $current);

    expect($member->tags)->toBe(['customer', 'vip']);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r['status_if_new'] === 'subscribed');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/tags') && $r['tags'] === [['name' => 'vip', 'status' => 'active']]);
});

it('updates with PATCH and never sends a status', function (): void {
    Http::fake([
        memberUrl() => Http::response(TestCase::fixture('mailchimp/member-unsubscribed')),
        memberUrl().'/tags' => Http::response(null, 204),
    ]);

    $member = mc()->update(new Contact('ada.lovelace@example.com', ['first_name' => 'Ada'], ['vip']));

    expect($member->status)->toBe(Status::Unsubscribed);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r->data() === ['merge_fields' => ['FNAME' => 'Ada']]);
    Http::assertNotSent(fn (Request $r): bool => isset($r['status']) || isset($r['status_if_new']));
});

it('unsubscribes with PATCH status=unsubscribed', function (): void {
    Http::fake([memberUrl() => Http::response(TestCase::fixture('mailchimp/member-unsubscribed'))]);

    expect(mc()->unsubscribe('ada.lovelace@example.com')->status)->toBe(Status::Unsubscribed);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r->data() === ['status' => 'unsubscribed']);
});

it('adds and removes tags in one request', function (): void {
    Http::fake([memberUrl().'/tags' => Http::response(null, 204)]);

    mc()->tag('ada.lovelace@example.com', ['vip'], ['trial']);

    Http::assertSent(fn (Request $r): bool => $r['tags'] === [['name' => 'vip', 'status' => 'active'], ['name' => 'trial', 'status' => 'inactive']]);
});

it('reads audience stats as percentages and campaign rates converted from fractions', function (): void {
    Http::fake([
        MC.'lists/aud123?*' => Http::response(TestCase::fixture('mailchimp/list')),
        MC.'campaigns?*' => Http::response(TestCase::fixture('mailchimp/campaigns')),
    ]);

    $stats = mc()->stats();
    $campaigns = mc()->campaigns();

    expect($stats->name)->toBe('Newsletter')
        ->and($stats->members)->toBe(4812)
        ->and($stats->unsubscribed)->toBe(120)
        ->and($stats->openRate)->toBe(42.4)
        ->and($stats->clickRate)->toBe(6.1)
        ->and($campaigns)->toHaveCount(2)
        ->and($campaigns[0]->title)->toBe('October product news')
        ->and($campaigns[0]->openRate)->toBe(42.4)
        ->and($campaigns[0]->clickRate)->toBe(6.1)
        ->and($campaigns[0]->recipients)->toBe(4790)
        ->and($campaigns[1]->title)->toBe('September digest');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'campaigns?') && $r['list_id'] === 'aud123' && $r['status'] === 'sent');
});

it('lists audiences for the lists command', function (): void {
    Http::fake([MC.'lists?*' => Http::response(TestCase::fixture('mailchimp/lists'))]);

    $this->artisan('audience-sync:lists')
        ->expectsTable(['ID', 'Name (members)'], [['aud123', 'Newsletter (4,812)'], ['aud456', 'Beta testers (57)']])
        ->assertSuccessful();
});

it('refuses to call the API without a key or list, or with a key without a data centre', function (): void {
    Http::fake();

    config()->set('audience-sync.drivers.mailchimp.api_key', '');
    app(AudienceSyncManager::class)->forgetDrivers();
    expect(fn () => mc()->member('a@example.com'))->toThrow(NotConfiguredException::class);

    config()->set('audience-sync.drivers.mailchimp.api_key', TestCase::MAILCHIMP_KEY);
    config()->set('audience-sync.drivers.mailchimp.list', '');
    app(AudienceSyncManager::class)->forgetDrivers();
    expect(fn () => mc()->member('a@example.com'))->toThrow(NotConfiguredException::class);

    config()->set('audience-sync.drivers.mailchimp.list', 'aud123');
    config()->set('audience-sync.drivers.mailchimp.api_key', '0123456789abcdef');
    config()->set('audience-sync.drivers.mailchimp.server', 'evil.example.com/x');
    app(AudienceSyncManager::class)->forgetDrivers();
    expect(fn () => mc()->member('a@example.com'))->toThrow(NotConfiguredException::class);

    Http::assertNothingSent();
});

it('never puts the API key in an error message', function (): void {
    Http::fake([memberUrl().'*' => Http::response(TestCase::fixture('mailchimp/api-key-invalid'), 401)]);

    try {
        mc()->member('ada.lovelace@example.com');
        $this->fail('Expected an exception');
    } catch (AudienceSyncException $e) {
        expect($e->getMessage())->toContain('API key may be invalid')
            ->not->toContain(TestCase::MAILCHIMP_KEY)
            ->not->toContain('0123456789abcdef')
            ->and($e->status)->toBe(401);
    }
});

it('includes field errors from Mailchimp', function (): void {
    Http::fake([memberUrl() => Http::response(TestCase::fixture('mailchimp/invalid-merge'), 400)]);

    expect(fn () => mc()->update(new Contact('ada.lovelace@example.com', ['phone' => 'x'])))
        ->toThrow(AudienceSyncException::class, 'Mailchimp: Your merge fields were invalid. (PHONE: Please enter a phone number.)');
});

it('retries a 429 after Retry-After, then succeeds', function (): void {
    Http::fake([memberUrl().'*' => Http::sequence()
        ->push(TestCase::fixture('mailchimp/too-many'), 429, ['Retry-After' => '2'])
        ->push(TestCase::fixture('mailchimp/member-subscribed'))]);

    expect(mc()->member('ada.lovelace@example.com')->status)->toBe(Status::Subscribed);
    Sleep::assertSlept(fn ($d): bool => (int) $d->totalMilliseconds === 2000, 1);
    Http::assertSentCount(2);
});

it('backs off exponentially on 5xx and connection errors', function (): void {
    Http::fake([memberUrl().'*' => Http::sequence()
        ->push('', 503)
        ->push('', 502)
        ->push(TestCase::fixture('mailchimp/member-subscribed'))]);

    mc()->member('ada.lovelace@example.com');

    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds()]);
});

it('gives up after the retries with a rate-limit exception carrying the wait', function (): void {
    Http::fake([memberUrl().'*' => Http::response(TestCase::fixture('mailchimp/too-many'), 429, ['Retry-After' => '5'])]);

    try {
        mc()->member('ada.lovelace@example.com');
        $this->fail('Expected an exception');
    } catch (RateLimitedException $e) {
        expect($e->retryAfter)->toBe(5);
    }

    Http::assertSentCount(4);
});

it('does not wait longer than max_wait_seconds inside a request', function (): void {
    Http::fake([memberUrl().'*' => Http::response(TestCase::fixture('mailchimp/too-many'), 429, ['Retry-After' => '120'])]);

    expect(fn () => mc()->member('ada.lovelace@example.com'))->toThrow(RateLimitedException::class);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});
