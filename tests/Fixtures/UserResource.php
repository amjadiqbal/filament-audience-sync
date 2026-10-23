<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Tests\Fixtures;

use AmjadIqbal\FilamentAudienceSync\Actions\AudienceActions;
use AmjadIqbal\FilamentAudienceSync\Infolists\AudienceStatusEntry;
use AmjadIqbal\FilamentAudienceSync\Tables\AudienceStatusColumn;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource\ListUsers;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\UserResource\ViewUser;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('email'),
            AudienceStatusEntry::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email'),
                AudienceStatusColumn::make(),
            ])
            ->recordActions([AudienceActions::group()])
            ->toolbarActions([AudienceActions::bulkGroup()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
