<?php

namespace App\Filament\Resources\Logs\AsinUserMpSyncResource\Pages;

use App\Filament\Resources\Logs\AsinUserMpSyncResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAsinUserMpSyncs extends ListRecords
{
    protected static string $resource = AsinUserMpSyncResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
