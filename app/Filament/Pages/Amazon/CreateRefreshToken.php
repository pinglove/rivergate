<?php

namespace App\Filament\Pages\Amazon;

use App\Filament\Resources\Amazon\RefreshTokenResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRefreshToken extends CreateRecord
{
    protected static string $resource = RefreshTokenResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Привязываем к текущему пользователю
        $data['user_id'] = auth()->id();

        // На всякий случай: если не выбрали — ставим manual
        $data['auth_type'] = $data['auth_type'] ?? 'manual';

        // Гарантируем дефолт региона, если вдруг форма не передала
        $data['sp_api_region'] = $data['sp_api_region'] ?? 'eu';

        return $data;
    }
}
