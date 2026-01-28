<?php

namespace App\Filament\Pages\Amazon;

use App\Filament\Resources\Amazon\RefreshTokenResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListRefreshTokens extends ListRecords
{
    protected static string $resource = RefreshTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add Refresh Token'),
        ];
    }
}
