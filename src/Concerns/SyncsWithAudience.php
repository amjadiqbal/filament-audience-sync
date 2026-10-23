<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Concerns;

use AmjadIqbal\FilamentAudienceSync\Facades\AudienceSync;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Defaults for the Subscribable contract: the "email" attribute, "name" split into first and last name, no tags.
 * Override any method on your model.
 *
 * @mixin Model
 */
trait SyncsWithAudience
{
    public function audienceEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function audienceFields(): array
    {
        $name = trim((string) $this->getAttribute('name'));

        if ($name === '') {
            return [];
        }

        [$first, $last] = array_pad(preg_split('/\s+/', $name, 2) ?: [], 2, null);

        return array_filter(['first_name' => $first, 'last_name' => $last], fn (?string $v): bool => $v !== null && $v !== '');
    }

    /**
     * @return list<string>
     */
    public function audienceTags(): array
    {
        return [];
    }

    /**
     * Every audience this record was synced to (one row per driver and list).
     *
     * @return MorphMany<SyncedMember, $this>
     */
    public function audienceMemberships(): MorphMany
    {
        return $this->morphMany(SyncedMember::class, 'subscribable');
    }

    /**
     * The row for the default driver's configured list: what AudienceStatusColumn shows (eager loaded by Filament).
     *
     * @return MorphOne<SyncedMember, $this>
     */
    public function audienceMembership(): MorphOne
    {
        return $this->morphOne(SyncedMember::class, 'subscribable')
            ->where('driver', AudienceSync::getDefaultDriver())
            ->where('list_id', AudienceSync::configuredList());
    }
}
