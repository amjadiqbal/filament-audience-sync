<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Infolists;

use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * The record's audience status on a view page, with when it was last synced, its tags and the last error.
 */
class AudienceStatusEntry extends TextEntry
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
            ->helperText(function (?Model $record): ?string {
                $row = $record?->getRelationValue('audienceMembership');

                if (! $row instanceof SyncedMember) {
                    return null;
                }

                $parts = [];

                if ($row->synced_at !== null) {
                    $parts[] = __('filament-audience-sync::audience-sync.column.synced', ['when' => $row->synced_at->diffForHumans()]);
                }

                if ($row->tags) {
                    $parts[] = __('filament-audience-sync::audience-sync.column.tags', ['tags' => implode(', ', $row->tags)]);
                }

                if ($row->last_error !== null) {
                    $parts[] = __('filament-audience-sync::audience-sync.column.last_error', ['error' => $row->last_error]);
                }

                return $parts === [] ? null : implode(' · ', $parts);
            });
    }

    protected static function status(mixed $state): ?Status
    {
        return $state instanceof Status ? $state : (is_string($state) ? Status::tryFrom($state) : null);
    }
}
