<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync;

use AmjadIqbal\FilamentAudienceSync\Commands\ListsCommand;
use AmjadIqbal\FilamentAudienceSync\Widgets\AudienceStatsWidget;
use AmjadIqbal\FilamentAudienceSync\Widgets\RecentCampaignsWidget;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentAudienceSyncServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-audience-sync';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('audience-sync')
            ->hasTranslations()
            ->hasMigration('create_audience_sync_members_table')
            ->hasCommand(ListsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AudienceSyncManager::class, fn ($app): AudienceSyncManager => new AudienceSyncManager($app));
    }

    public function packageBooted(): void
    {
        // Registered by name, so the widgets' Livewire updates resolve wherever they are placed, including a custom
        // dashboard that adds them by class.
        Livewire::component('amjadiqbal.filament-audience-sync.audience-stats-widget', AudienceStatsWidget::class);
        Livewire::component('amjadiqbal.filament-audience-sync.recent-campaigns-widget', RecentCampaignsWidget::class);
    }
}
