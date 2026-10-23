<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Widgets;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\AudienceSyncPlugin;
use AmjadIqbal\FilamentAudienceSync\Contracts\AudienceDriver;
use AmjadIqbal\FilamentAudienceSync\Data\Campaign;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Throwable;

/**
 * The latest sent campaigns to the configured list, with open and click rates (cached).
 */
class RecentCampaignsWidget extends TableWidget
{
    public ?string $driver = null;

    public ?string $error = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! AudienceSyncPlugin::get()->can('stats')) {
            return false;
        }

        try {
            return app(AudienceSyncManager::class)->driver()->supports(AudienceDriver::FEATURE_CAMPAIGNS);
        } catch (Throwable) {
            return false;
        }
    }

    public function table(Table $table): Table
    {
        $t = fn (string $key): string => __("filament-audience-sync::audience-sync.campaigns.{$key}");
        $rate = fn (mixed $state): string => $state === null ? '—' : number_format((float) $state, 1).'%';

        return $table
            ->heading($t('heading'))
            ->records(function (): array {
                try {
                    $this->error = null;

                    return collect(app(AudienceSyncManager::class)->campaigns(5, $this->driver))
                        ->mapWithKeys(fn (Campaign $c): array => [$c->id => $c->toArray()])
                        ->all();
                } catch (AudienceSyncException $e) {
                    $this->error = $e->getMessage();

                    return [];
                }
            })
            ->columns([
                TextColumn::make('title')->label($t('title'))->weight('medium')->wrap(),
                TextColumn::make('sent_at')->label($t('sent_at'))->dateTime()->placeholder('—'),
                TextColumn::make('recipients')->label($t('recipients'))->numeric(),
                TextColumn::make('open_rate')->label($t('open_rate'))->formatStateUsing($rate)->placeholder('—'),
                TextColumn::make('click_rate')->label($t('click_rate'))->formatStateUsing($rate)->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->error !== null ? $t('unavailable') : $t('empty'))
            ->emptyStateDescription(fn (): ?string => $this->error);
    }
}
