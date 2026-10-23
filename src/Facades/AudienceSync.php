<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Facades;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AmjadIqbal\FilamentAudienceSync\Contracts\AudienceDriver driver(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static string configuredList(?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\Data\SyncResult subscribe(\Illuminate\Database\Eloquent\Model $record, ?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\Data\SyncResult update(\Illuminate\Database\Eloquent\Model $record, ?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\Data\SyncResult unsubscribe(\Illuminate\Database\Eloquent\Model $record, ?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\Data\SyncResult refresh(\Illuminate\Database\Eloquent\Model $record, ?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\Data\SyncResult tag(\Illuminate\Database\Eloquent\Model $record, array $add, array $remove = [], ?string $driver = null)
 * @method static int queue(iterable $records, string $operation, ?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\Data\ListStats stats(?string $driver = null)
 * @method static list<\AmjadIqbal\FilamentAudienceSync\Data\Campaign> campaigns(int $limit = 5, ?string $driver = null)
 * @method static \AmjadIqbal\FilamentAudienceSync\AudienceSyncManager extend(string $driver, \Closure $callback)
 *
 * @see AudienceSyncManager
 */
class AudienceSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AudienceSyncManager::class;
    }
}
