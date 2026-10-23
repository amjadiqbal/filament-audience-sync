<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Models;

use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The last known state of one record in one audience: what the status column shows without calling the provider.
 * An "unsubscribed" row is also the local record of that person's choice, and blocks any later subscribe.
 *
 * @property int $id
 * @property string $subscribable_type
 * @property int|string $subscribable_id
 * @property string $driver
 * @property string $list_id
 * @property string $email
 * @property Status $status
 * @property list<string>|null $tags
 * @property string|null $provider_id
 * @property string|null $last_error
 * @property Carbon|null $synced_at
 * @property Carbon|null $unsubscribed_at
 */
class SyncedMember extends Model
{
    protected $table = 'audience_sync_members';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'tags' => 'array',
            'synced_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
