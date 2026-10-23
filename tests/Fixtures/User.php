<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Tests\Fixtures;

use AmjadIqbal\FilamentAudienceSync\Concerns\SyncsWithAudience;
use AmjadIqbal\FilamentAudienceSync\Contracts\Subscribable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property ?string $email
 */
class User extends Authenticatable implements FilamentUser, Subscribable
{
    use SyncsWithAudience;

    /** @var list<string> */
    public static array $tags = [];

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function audienceTags(): array
    {
        return static::$tags;
    }
}
