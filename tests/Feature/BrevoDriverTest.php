<?php

declare(strict_types=1);

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\Contracts\AudienceDriver;
use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\Member;
use AmjadIqbal\FilamentAudienceSync\Drivers\BrevoDriver;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use AmjadIqbal\FilamentAudienceSync\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const BREVO = 'https://api.brevo.com/v3/';

function brevo(): BrevoDriver
{
    /** @var BrevoDriver */
    return app(AudienceSyncManager::class)->driver('brevo');
}

it('maps contact state on list 7 to a status', function (string $fixture, int $code, Status $status): void {
    Http::fake([BREVO.'contacts/*' => Http::response(TestCase::fixture("brevo/{$fixture}"), $code)]);

    expect(brevo()->member('Ada.Lovelace@example.com')->status)->toBe($status);
    Http::assertSent(fn (Request $r): bool => $r->url() === BREVO.'contacts/ada.lovelace%40example.com' && $r->hasHeader('api-key', TestCase::BREVO_KEY));
})->with([
    ['contact', 200, Status::Subscribed],
    ['contact-blocklisted', 200, Status::Unsubscribed],
    ['contact-list-unsubscribed', 200, Status::Unsubscribed],
    ['contact-other-list', 200, Status::NotSubscribed],
    ['not-found', 404, Status::NotSubscribed],
]);

it('subscribes by adding the contact to the list, never lifting a blocklisting', function (): void {
    Http::fake([BREVO.'contacts' => Http::response(TestCase::fixture('brevo/created'), 201)]);

    $member = brevo()->subscribe(new Contact('Ada.Lovelace@example.com', ['first_name' => 'Ada', 'phone' => '+15550100'], ['ignored']), Member::absent('ada.lovelace@example.com'));

    expect($member->status)->toBe(Status::Subscribed)->and($member->providerId)->toBe('42');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->data() === [
        'email' => 'ada.lovelace@example.com',
        'listIds' => [7],
        'updateEnabled' => true,
        'attributes' => ['FIRSTNAME' => 'Ada', 'SMS' => '+15550100'],
    ]);
});

it('updates attributes only', function (): void {
    Http::fake([BREVO.'contacts/*' => Http::sequence()->push(null, 204)->push(TestCase::fixture('brevo/contact'))]);

    expect(brevo()->update(new Contact('ada.lovelace@example.com', ['last_name' => 'Byron']))->status)->toBe(Status::Subscribed);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r->data() === ['attributes' => ['LASTNAME' => 'Byron']]);
});

it('unsubscribes by blocklisting, or by unlinking the list', function (string $mode, array $body): void {
    config()->set('audience-sync.drivers.brevo.unsubscribe', $mode);
    app(AudienceSyncManager::class)->forgetDrivers();
    Http::fake([BREVO.'contacts/*' => Http::response(null, 204)]);

    expect(brevo()->unsubscribe('ada.lovelace@example.com')->status)->toBe(Status::Unsubscribed);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r->data() === $body);
})->with([
    ['blocklist', ['emailBlacklisted' => true]],
    ['unlink', ['unlinkListIds' => [7]]],
]);

it('has no tags or double opt-in', function (): void {
    expect(brevo()->supports(AudienceDriver::FEATURE_TAGS))->toBeFalse()
        ->and(brevo()->supports(AudienceDriver::FEATURE_DOUBLE_OPT_IN))->toBeFalse()
        ->and(brevo()->supports(AudienceDriver::FEATURE_CAMPAIGNS))->toBeTrue()
        ->and(fn () => brevo()->tag('a@example.com', ['x']))->toThrow(AudienceSyncException::class);
});

it('reads list stats and only this list\'s campaigns', function (): void {
    Http::fake([
        BREVO.'contacts/lists/7' => Http::response(TestCase::fixture('brevo/list')),
        BREVO.'emailCampaigns?*' => Http::response(TestCase::fixture('brevo/campaigns')),
    ]);

    $campaigns = brevo()->campaigns();
    $stats = brevo()->stats();

    expect(array_map(fn ($c) => $c->title, $campaigns))->toBe(['October news', 'September news'])
        ->and($campaigns[0]->openRate)->toBe(40.0)
        ->and($campaigns[0]->clickRate)->toBe(5.0)
        ->and($campaigns[0]->recipients)->toBe(905)
        ->and($stats->members)->toBe(990)
        ->and($stats->unsubscribed)->toBe(15)
        ->and($stats->openRate)->toBe(38.8)
        ->and($stats->clickRate)->toBe(4.4);
});

it('redacts the key from Brevo errors', function (): void {
    Http::fake([BREVO.'contacts/*' => Http::response(TestCase::fixture('brevo/unauthorized'), 401)]);

    expect(fn () => brevo()->member('a@example.com'))
        ->toThrow(fn (AudienceSyncException $e) => expect($e->getMessage())->toBe('Brevo: Key not found: [redacted]'));
});

it('lists Brevo lists', function (): void {
    Http::fake([BREVO.'contacts/lists?*' => Http::response(TestCase::fixture('brevo/lists'))]);

    expect(brevo()->lists())->toBe(['7' => 'Newsletter (990)', '3' => 'Customers (120)']);
});
