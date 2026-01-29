<?php

namespace App\Filament\Resources\Amazon\Orders\OrderResource\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Amazon\Orders\OrderResource;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return []; // никаких Create
    }
}
