<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource;

use AmjadIqbal\FilamentAudienceSync\Actions\AudienceActions;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AudienceActions::subscribe(),
            AudienceActions::update(),
            AudienceActions::tags(),
            AudienceActions::unsubscribe(),
        ];
    }
}
