<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Actions;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\AudienceSyncPlugin;
use AmjadIqbal\FilamentAudienceSync\Contracts\AudienceDriver;
use AmjadIqbal\FilamentAudienceSync\Data\SyncResult;
use AmjadIqbal\FilamentAudienceSync\Enums\Outcome;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Record and bulk actions. Put them on any resource whose model implements Subscribable:
 *   ->recordActions([AudienceActions::group()])   ->toolbarActions([AudienceActions::bulkGroup()])
 * Pass a driver name to target another provider than the default.
 */
class AudienceActions
{
    public static function group(?string $driver = null): ActionGroup
    {
        return ActionGroup::make([
            static::subscribe($driver),
            static::update($driver),
            static::tags($driver),
            static::unsubscribe($driver),
        ])
            ->label(static::t('actions.group'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->button();
    }

    public static function bulkGroup(?string $driver = null): ActionGroup
    {
        return ActionGroup::make([
            static::bulkSubscribe($driver),
            static::bulkUpdate($driver),
            static::bulkUnsubscribe($driver),
        ])
            ->label(static::t('actions.group'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->button();
    }

    public static function subscribe(?string $driver = null): Action
    {
        return Action::make('audienceSubscribe')
            ->label(static::t('actions.subscribe'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (?Model $record): bool => AudienceSyncPlugin::get()->can('subscribe', $record)
                && ! in_array(static::localStatus($record, $driver), [Status::Subscribed, Status::Pending], true))
            ->disabled(fn (?Model $record): bool => static::localStatus($record, $driver)?->blocksSubscribe() ?? false)
            ->tooltip(fn (?Model $record): ?string => (static::localStatus($record, $driver)?->blocksSubscribe() ?? false) ? static::t('actions.subscribe_blocked') : null)
            ->action(fn (Model $record) => static::runAndNotify('subscribe', $record, $driver));
    }

    public static function update(?string $driver = null): Action
    {
        return Action::make('audienceUpdate')
            ->label(static::t('actions.update'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (?Model $record): bool => AudienceSyncPlugin::get()->can('update', $record))
            ->action(fn (Model $record) => static::runAndNotify('update', $record, $driver));
    }

    public static function unsubscribe(?string $driver = null): Action
    {
        return Action::make('audienceUnsubscribe')
            ->label(static::t('actions.unsubscribe'))
            ->icon(Heroicon::OutlinedNoSymbol)
            // Gray, not red: a solid red button turns a muddy dark red under a modal backdrop (no-warm-fills rule).
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedNoSymbol)
            ->modalIconColor('gray')
            ->modalHeading(static::t('actions.unsubscribe_heading'))
            ->modalDescription(static::t('actions.unsubscribe_description'))
            ->modalSubmitActionLabel(static::t('actions.unsubscribe'))
            ->visible(fn (?Model $record): bool => AudienceSyncPlugin::get()->can('unsubscribe', $record)
                && static::localStatus($record, $driver) !== Status::Unsubscribed)
            ->action(fn (Model $record) => static::runAndNotify('unsubscribe', $record, $driver));
    }

    public static function tags(?string $driver = null): Action
    {
        return Action::make('audienceTags')
            ->label(static::t('actions.tags'))
            ->icon(Heroicon::OutlinedTag)
            ->color('gray')
            ->visible(fn (?Model $record): bool => AudienceSyncPlugin::get()->can('tag', $record)
                && static::driverSupports($driver, AudienceDriver::FEATURE_TAGS))
            ->modalHeading(static::t('actions.tags'))
            ->modalSubmitActionLabel(static::t('actions.tags_submit'))
            ->schema([
                TagsInput::make('add')
                    ->label(static::t('actions.tags_add')),
                TagsInput::make('remove')
                    ->label(static::t('actions.tags_remove'))
                    ->suggestions(fn (?Model $record): array => $record !== null ? (app(AudienceSyncManager::class)->membership($record, $driver)->tags ?? []) : []),
            ])
            ->action(function (Model $record, array $data) use ($driver): void {
                static::notify(static::guard(fn (): SyncResult => app(AudienceSyncManager::class)->tag(
                    $record,
                    array_values((array) ($data['add'] ?? [])),
                    array_values((array) ($data['remove'] ?? [])),
                    $driver,
                )));
            });
    }

    public static function bulkSubscribe(?string $driver = null): BulkAction
    {
        return static::bulk('audienceBulkSubscribe', 'subscribe', $driver)
            ->label(static::t('actions.bulk_subscribe'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalDescription(static::t('actions.bulk_subscribe_description'));
    }

    public static function bulkUpdate(?string $driver = null): BulkAction
    {
        return static::bulk('audienceBulkUpdate', 'update', $driver)
            ->label(static::t('actions.bulk_update'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalDescription(static::t('actions.bulk_update_description'));
    }

    public static function bulkUnsubscribe(?string $driver = null): BulkAction
    {
        return static::bulk('audienceBulkUnsubscribe', 'unsubscribe', $driver)
            ->label(static::t('actions.bulk_unsubscribe'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->modalDescription(static::t('actions.bulk_unsubscribe_description'));
    }

    protected static function bulk(string $name, string $operation, ?string $driver): BulkAction
    {
        return BulkAction::make($name)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIconColor('gray')
            ->visible(fn (): bool => AudienceSyncPlugin::get()->can($operation))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($operation, $driver): void {
                $count = app(AudienceSyncManager::class)->queue($records, $operation, $driver);

                Notification::make()
                    ->title(trans_choice('filament-audience-sync::audience-sync.notifications.queued', $count, ['count' => $count]))
                    ->body(static::t('notifications.queued_body'))
                    ->success()
                    ->send();
            });
    }

    protected static function runAndNotify(string $operation, Model $record, ?string $driver): void
    {
        abort_unless(AudienceSyncPlugin::get()->can($operation, $record), 403);

        static::notify(static::guard(fn (): SyncResult => app(AudienceSyncManager::class)->{$operation}($record, $driver)));
    }

    /**
     * @param  callable(): SyncResult  $callback
     */
    protected static function guard(callable $callback): SyncResult
    {
        try {
            return $callback();
        } catch (AudienceSyncException $e) {
            return new SyncResult(Outcome::Failed, null, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return new SyncResult(Outcome::Failed, null, static::t('errors.unexpected'));
        }
    }

    public static function notify(SyncResult $result): void
    {
        $notification = Notification::make()->title($result->outcome->label());

        match (true) {
            $result->outcome === Outcome::Failed => $notification->body($result->error)->danger(),
            $result->outcome->isSkipped() => $notification->body(static::t("notifications.{$result->outcome->value}"))->info(),
            default => $notification->body($result->status !== null ? static::t('notifications.status', ['status' => $result->status->label()]) : null)->success(),
        };

        $notification->send();
    }

    protected static function localStatus(?Model $record, ?string $driver): ?Status
    {
        if ($record === null || ! $record->exists) {
            return null;
        }

        if ($driver === null && $record->relationLoaded('audienceMembership')) {
            /** @var SyncedMember|null $row */
            $row = $record->getRelation('audienceMembership');

            return $row?->status;
        }

        return app(AudienceSyncManager::class)->membership($record, $driver)?->status;
    }

    protected static function driverSupports(?string $driver, string $feature): bool
    {
        try {
            return app(AudienceSyncManager::class)->driver($driver)->supports($feature);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected static function t(string $key, array $replace = []): string
    {
        return __("filament-audience-sync::audience-sync.{$key}", $replace);
    }
}
