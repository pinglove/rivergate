<?php

namespace App\Filament\Resources\Logs\ReviewRequestQueueResource\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Logs\ReviewRequestQueueResource;

class ListReviewRequestQueues extends ListRecords
{
    protected static string $resource = ReviewRequestQueueResource::class;
}
