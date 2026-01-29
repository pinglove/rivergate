<?php

namespace App\Filament\Resources\Amazon\Asins\AsinResource\Pages;

use App\Filament\Resources\Amazon\Asins\AsinResource;
use App\Models\Amazon\Asins\Asin;
use App\Models\Amazon\Asins\AsinListingSync;
use App\Models\Amazon\Asins\AsinUserMpSync;
use App\Models\Amazon\Asins\AsinUserMpSyncLog;
use App\Support\Asins\AsinCatalogSyncPolicy;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAsins extends ListRecords
{
    protected static string $resource = AsinResource::class;

    public ?string $syncHint = null;

    public function mount(): void
    {
        parent::mount();
        $this->loadLastSyncAndHint();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync')
                ->icon('heroicon-o-arrow-path')
                ->disabled(fn () => ! AsinCatalogSyncPolicy::canStart(
                    auth()->id(),
                    (int) session('active_marketplace')
                ))
                ->tooltip(fn () => $this->syncHint ?? '')
                ->action(fn () => $this->startSync()),

            Action::make('syncListing')
                ->label('Sync Listing')
                ->icon('heroicon-o-arrow-path')
                ->disabled(fn () => $this->eligibleListingSyncCount() === 0)
                ->action(fn () => $this->startListingSync()),
        ];
    }

    protected function loadLastSyncAndHint(): void
    {
        $userId = auth()->id();
        $marketplaceId = (int) session('active_marketplace');

        $lastSync = AsinUserMpSync::where('user_id', $userId)
            ->where('marketplace_id', $marketplaceId)
            ->latest()
            ->first();

        if (! $lastSync) {
            $this->syncHint = 'No previous syncs';
            return;
        }

        if (! AsinCatalogSyncPolicy::canStart($userId, $marketplaceId)) {
            $this->syncHint = 'Sync in progress';
            return;
        }

        $this->syncHint = 'Ready to sync';
    }

    protected function startSync(): void
    {
        AsinUserMpSync::create([
            'user_id'        => auth()->id(),
            'marketplace_id' => (int) session('active_marketplace'),
            'status'         => 'pending',
            'attempts'       => 0,
        ]);

        Notification::make()
            ->title('ASIN catalog sync started')
            ->success()
            ->send();
    }

    protected function eligibleListingSyncCount(): int
    {
        return Asin::eligibleForListingSync(
            auth()->id(),
            (int) session('active_marketplace')
        )->count();
    }

    protected function startListingSync(): void
    {
        $asins = Asin::eligibleForListingSync(
            auth()->id(),
            (int) session('active_marketplace')
        )->get();

        foreach ($asins as $asin) {
            AsinListingSync::create([
                'user_id'        => auth()->id(),
                'marketplace_id' => (int) session('active_marketplace'),
                'asin_id'        => $asin->id,
                'status'         => 'pending',
                'pipeline'       => 'pending',
            ]);
        }

        Notification::make()
            ->title('Listing sync queued')
            ->success()
            ->send();
    }
}
