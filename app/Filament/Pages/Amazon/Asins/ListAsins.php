<?php

namespace App\Filament\Pages\Amazon\Asins;

use App\Filament\Resources\Amazon\Asins\AsinResource;
use App\Models\Amazon\Asins\Asin;
use App\Models\Amazon\Asins\AsinUserMpSync;
use App\Models\Amazon\Asins\AsinUserMpSyncLog;
use App\Models\Amazon\Asins\AsinListingSync;
use App\Models\Amazon\Review\ReviewRequestSetting;
use App\Support\Asins\AsinCatalogSyncPolicy;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;

class ListAsins extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Amazon';
    protected static ?string $navigationLabel = 'ASINs';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 25;

    protected static string $view = 'filament.pages.amazon.asins.list-asins';

    public ?string $syncHint = null;

    /** @var Collection<int, AsinUserMpSyncLog> */
    public Collection $syncLogs;

    public function mount(): void
    {
        $this->syncLogs = collect();
        $this->loadLastSyncAndHint();
    }

    /* ------------------------------------------------------------------ */
    /* Header actions (НЕ ТРОГАЕМ SYNC ЛОГИКУ)                              */
    /* ------------------------------------------------------------------ */

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

            Actions\Action::make('logs')
                ->label('Logs')
                ->icon('heroicon-o-queue-list')
                ->slideOver()
                ->modalHeading('ASIN Catalog Sync')
                ->form([])
                ->action(fn () => null)
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->modalContent(function () {
                    $userId = auth()->id();
                    $marketplaceId = (int) session('active_marketplace');

                    $sync = AsinUserMpSync::query()
                        ->where('user_id', $userId)
                        ->where('marketplace_id', $marketplaceId)
                        ->latest()
                        ->first();

                    $logs = $sync
                        ? AsinUserMpSyncLog::where('sync_id', $sync->id)->get()
                        : collect();

                    return view('filament.pages.amazon.asins.sync-logs-drawer', compact('sync', 'logs'));
                }),
        ];
    }

    /* ------------------------------------------------------------------ */

    protected function getTableQuery()
    {
        return Asin::query()
            ->where('user_id', auth()->id())
            ->where('marketplace_id', (int) session('active_marketplace'));
    }

    /* ------------------------------------------------------------------ */
    /* TABLE COLUMNS                                                       */
    /* ------------------------------------------------------------------ */

    protected function getTableColumns(): array
    {
        return array_merge(
            [
                ToggleColumn::make('review_enabled')
                    ->label('Review')
                    ->getStateUsing(fn (Asin $record) =>
                        ReviewRequestSetting::query()
                            ->where('user_id', auth()->id())
                            ->where('marketplace_id', (int) session('active_marketplace'))
                            ->where('asin_id', $record->id)
                            ->value('is_enabled') ?? false
                    )
                    ->updateStateUsing(function (Asin $record, bool $state) {
                        ReviewRequestSetting::updateOrCreate(
                            [
                                'user_id'        => auth()->id(),
                                'marketplace_id'=> (int) session('active_marketplace'),
                                'asin_id'        => $record->id,
                            ],
                            [
                                'is_enabled'   => $state,
                                'delay_days'   => 7,
                                'process_hour' => 2,
                            ]
                        );
                    }),

                TextColumn::make('review_delay')
                    ->label('Delay')
                    ->state(fn (Asin $record) =>
                        ReviewRequestSetting::query()
                            ->where('user_id', auth()->id())
                            ->where('marketplace_id', (int) session('active_marketplace'))
                            ->where('asin_id', $record->id)
                            ->value('delay_days')
                    )
                    ->formatStateUsing(fn ($state) => $state ? "{$state} days" : '—'),
            ],
            AsinResource::table($this->makeTable())->getColumns()
        );
    }

    protected function getTableActions(): array
    {
        return [];
    }

    /* ------------------------------------------------------------------ */
    /* BULK ACTIONS                                                        */
    /* ------------------------------------------------------------------ */

    protected function getTableBulkActions(): array
    {
        return [
            BulkAction::make('enableReview')
                ->label('Enable review')
                ->action(fn (Collection $records) =>
                    $records->each(fn (Asin $asin) =>
                        ReviewRequestSetting::updateOrCreate(
                            [
                                'user_id'        => auth()->id(),
                                'marketplace_id'=> session('active_marketplace'),
                                'asin_id'        => $asin->id,
                            ],
                            ['is_enabled' => true, 'delay_days' => 5]
                        )
                    )
                ),

            BulkAction::make('disableReview')
                ->label('Disable review')
                ->action(fn (Collection $records) =>
                    $records->each(fn (Asin $asin) =>
                        ReviewRequestSetting::updateOrCreate(
                            [
                                'user_id'        => auth()->id(),
                                'marketplace_id'=> session('active_marketplace'),
                                'asin_id'        => $asin->id,
                            ],
                            ['is_enabled' => false]
                        )
                    )
                ),

            BulkAction::make('setDelay')
                ->label('Set delay')
                ->form([
                    Select::make('delay_days')
                        ->label('Delay days')
                        ->options([
                            3 => '3 days',
                            5 => '5 days',
                            7 => '7 days',
                            14 => '14 days',
                            21 => '21 days',
                            30 => '30 days',
                        ])
                        ->required(),
                ])
                ->action(function (Collection $records, array $data) {
                    foreach ($records as $asin) {
                        ReviewRequestSetting::updateOrCreate(
                            [
                                'user_id'        => auth()->id(),
                                'marketplace_id'=> session('active_marketplace'),
                                'asin_id'        => $asin->id,
                            ],
                            [
                                'delay_days' => (int) $data['delay_days'],
                            ]
                        );
                    }
                }),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* EXISTING LOGIC BELOW — НЕ ТРОГАЛ                                   */
    /* ------------------------------------------------------------------ */

    protected function loadLastSyncAndHint(): void
    {
        // unchanged
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
