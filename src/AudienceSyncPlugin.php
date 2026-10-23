<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync;

use AmjadIqbal\FilamentAudienceSync\Widgets\AudienceStatsWidget;
use AmjadIqbal\FilamentAudienceSync\Widgets\RecentCampaignsWidget;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

class AudienceSyncPlugin implements Plugin
{
    public const ID = 'audience-sync';

    /**
     * Abilities the actions and widgets check.
     */
    public const ABILITIES = ['subscribe', 'unsubscribe', 'update', 'tag', 'stats'];

    /**
     * An app-wide gate that, when defined, every ability must pass: Gate::define('manageAudience', fn ($user, string
     * $ability, ?Model $record) => ...).
     */
    public const GATE = 'manageAudience';

    protected bool $hasDashboardWidgets = true;

    protected ?Closure $authorizeUsing = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The plugin registered on the current panel, or a default instance outside a panel.
     */
    public static function get(): static
    {
        try {
            $panel = Filament::getCurrentOrDefaultPanel();

            if ($panel?->hasPlugin(static::ID)) {
                /** @var static */
                return $panel->getPlugin(static::ID);
            }
        } catch (Throwable) {
            // No panels registered.
        }

        return app(static::class);
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        if ($this->hasDashboardWidgets) {
            $panel->widgets([AudienceStatsWidget::class, RecentCampaignsWidget::class]);
        }
    }

    public function boot(Panel $panel): void {}

    /**
     * Show the list stats and recent campaigns widgets on the panel dashboard.
     */
    public function dashboardWidgets(bool $condition = true): static
    {
        $this->hasDashboardWidgets = $condition;

        return $this;
    }

    public function hasDashboardWidgets(): bool
    {
        return $this->hasDashboardWidgets;
    }

    /**
     * Decide who may do what. The closure receives `$ability` (see ABILITIES), `$record` (or null) and `$user`, and
     * returns a bool. It replaces the gate check.
     */
    public function authorizeUsing(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function can(string $ability, ?Model $record = null): bool
    {
        $user = static::user();

        if ($this->authorizeUsing !== null) {
            return (bool) app()->call($this->authorizeUsing, ['ability' => $ability, 'record' => $record, 'user' => $user]);
        }

        if (Gate::has(static::GATE)) {
            return $user !== null && Gate::forUser($user)->allows(static::GATE, [$ability, $record]);
        }

        // No rule set: whoever can reach the panel may use it.
        return true;
    }

    public static function user(): ?Authenticatable
    {
        try {
            return Filament::auth()->user();
        } catch (Throwable) {
            return auth()->user();
        }
    }
}
