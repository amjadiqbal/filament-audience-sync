<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Jobs;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\Exceptions\RateLimitedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One chunk of a bulk action. Every operation is idempotent, so a chunk released after a rate limit simply runs again.
 * A record that fails is recorded on its status row (last_error) and the chunk carries on.
 */
class SyncAudienceChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    /**
     * @param  class-string<Model>  $model
     * @param  list<int|string>  $ids
     * @param  'subscribe'|'update'|'unsubscribe'|'refresh'  $operation
     */
    public function __construct(
        public string $model,
        public array $ids,
        public string $operation,
        public ?string $driver = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(AudienceSyncManager $sync): void
    {
        $records = $this->model::query()->whereKey($this->ids)->get();

        foreach ($records as $record) {
            try {
                $sync->{$this->operation}($record, $this->driver);
            } catch (RateLimitedException $e) {
                $this->release(max($e->retryAfter, 30));

                return;
            }
        }
    }
}
