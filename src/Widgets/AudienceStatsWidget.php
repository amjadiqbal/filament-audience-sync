<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Widgets;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\AudienceSyncPlugin;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Members, unsubscribes and average open and click rates of the configured list (cached, see cache_seconds).
 */
class AudienceStatsWidget extends StatsOverviewWidget
{
    public ?string $driver = null;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return AudienceSyncPlugin::get()->can('stats');
    }

    protected function getHeading(): ?string
    {
        return __('filament-audience-sync::audience-sync.stats.heading');
    }

    protected function getStats(): array
    {
        $t = fn (string $key, array $replace = []): string => __("filament-audience-sync::audience-sync.stats.{$key}", $replace);

        try {
            $s = app(AudienceSyncManager::class)->stats($this->driver);
        } catch (AudienceSyncException $e) {
            return [
                Stat::make($t('unavailable'), '—')
                    ->description($e->getMessage())
                    ->color('gray'),
            ];
        }

        $rate = fn (?float $r): string => $r === null ? '—' : number_format($r, 1).'%';

        return [
            Stat::make($t('members'), number_format($s->members))
                ->description($s->name !== '' ? $s->name : null)
                ->color('gray'),
            Stat::make($t('unsubscribed'), $s->unsubscribed === null ? '—' : number_format($s->unsubscribed))
                ->description($t('unsubscribed_help'))
                ->color('gray'),
            Stat::make($t('open_rate'), $rate($s->openRate))
                ->description($t('rate_help'))
                ->color('gray'),
            Stat::make($t('click_rate'), $rate($s->clickRate))
                ->description($t('rate_help'))
                ->color('gray'),
        ];
    }
}
