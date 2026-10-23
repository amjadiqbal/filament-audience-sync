<?php

declare(strict_types=1);

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Facades\AudienceSync;
use AmjadIqbal\FilamentAudienceSync\Jobs\SyncAudienceChunk;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\User;
use AmjadIqbal\FilamentAudienceSync\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues one job per chunk', function (): void {
    config()->set('audience-sync.queue.chunk', 2);
    config()->set('audience-sync.queue.name', 'audience');
    Queue::fake();
    $users = collect(range(1, 5))->map(fn (int $i) => $this->makeUser("User {$i}"));

    expect(AudienceSync::queue($users, 'subscribe'))->toBe(5);

    Queue::assertPushed(SyncAudienceChunk::class, 3);
    Queue::assertPushedOn('audience', SyncAudienceChunk::class);
    Queue::assertPushed(SyncAudienceChunk::class, fn (SyncAudienceChunk $job): bool => $job->ids === [$users[4]->id] && $job->operation === 'subscribe' && $job->model === User::class);
});

it('rejects unknown operations', function (): void {
    expect(fn () => AudienceSync::queue([], 'delete'))->toThrow(InvalidArgumentException::class);
});

it('syncs each record of a chunk and carries on past a failing one', function (): void {
    $ada = $this->makeUser();
    $bob = $this->makeUser('Bob Builder');
    Http::fake([
        'https://us21.api.mailchimp.com/3.0/lists/aud123/members/'.md5('ada.lovelace@example.com').'*' => Http::response(TestCase::fixture('mailchimp/api-key-invalid'), 401),
        'https://us21.api.mailchimp.com/3.0/lists/aud123/members/'.md5('bob.builder@example.com').'*' => Http::response(TestCase::fixture('mailchimp/member-subscribed')),
    ]);

    (new SyncAudienceChunk(User::class, [$ada->id, $bob->id], 'refresh'))->handle(app(AudienceSyncManager::class));

    expect(AudienceSync::membership($ada)?->last_error)->toContain('API key may be invalid')
        ->and(AudienceSync::membership($bob)?->status)->toBe(Status::Subscribed);
});

it('releases the chunk when the provider keeps rate limiting', function (): void {
    $ada = $this->makeUser();
    Http::fake(['*' => Http::response(TestCase::fixture('mailchimp/too-many'), 429, ['Retry-After' => '45'])]);

    $job = new SyncAudienceChunk(User::class, [$ada->id], 'subscribe');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(45);
    $job->setJob($queueJob);

    $job->handle(app(AudienceSyncManager::class));
});
