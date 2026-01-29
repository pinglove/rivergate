<?php

namespace App\Filament\Resources\Logs\OrdersSyncResource\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Logs\OrdersSyncResource;

class ListOrdersSyncs extends ListRecords
{
    protected static string $resource = OrdersSyncResource::class;
}
