<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource;

use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
