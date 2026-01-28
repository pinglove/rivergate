<?php

namespace App\Filament\Pages\Amazon;

use App\Filament\Resources\Amazon\RefreshTokenResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditRefreshToken extends EditRecord
{
    protected static string $resource = RefreshTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // жёстко привязываем запись к текущему пользователю
        $data['user_id'] = auth()->id();

        // подстраховка: регион не должен стать NULL
        if (empty($data['sp_api_region'])) {
            $data['sp_api_region'] = 'eu';
        }

        return $data;
    }
}
