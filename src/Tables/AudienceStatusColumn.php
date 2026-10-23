<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Tables;

use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * The record's audience status for the default driver and list, read from the local status row (eager loaded, no
 * API call). A red icon marks a record whose last sync failed; hover it for the reason.
 */
class AudienceStatusColumn extends TextColumn
{
    public static function getDefaultName(): ?string
    {
        return 'audienceMembership.status';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-audience-sync::audience-sync.column.label'))
            ->formatStateUsing(fn (mixed $state): string => static::status($state)?->label() ?? '—')
            ->color(fn (mixed $state): string => static::status($state)?->color() ?? 'gray')
            ->placeholder(__('filament-audience-sync::audience-sync.column.not_synced'))
            ->weight('medium')
            ->icon(fn (Model $record): ?Heroicon => static::row($record)?->last_error !== null ? Heroicon::OutlinedExclamationCircle : null)
            ->iconColor('danger')
            ->tooltip(function (Model $record): ?string {
                $row = static::row($record);

                if ($row === null) {
                    return null;
                }

                return $row->last_error ?? ($row->synced_at !== null
                    ? __('filament-audience-sync::audience-sync.column.synced', ['when' => $row->synced_at->diffForHumans()])
                    : null);
            });
    }

    protected static function status(mixed $state): ?Status
    {
        return $state instanceof Status ? $state : (is_string($state) ? Status::tryFrom($state) : null);
    }

    protected static function row(Model $record): ?SyncedMember
    {
        $row = $record->relationLoaded('audienceMembership') ? $record->getRelation('audienceMembership') : null;

        return $row instanceof SyncedMember ? $row : null;
    }
}
