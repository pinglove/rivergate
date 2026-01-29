<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinListingSyncRequest;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\Logs\AsinListingSyncRequestResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class AsinListingSyncRequestResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinListingSyncRequest::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Listing Sync/Requests';
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?int $navigationSort = 21;

    /* 🔐 Только super_admin */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                AsinListingSyncRequest::query()
                    ->join(
                        'asins_asin_listing_sync',
                        'asins_asin_listing_sync.id',
                        '=',
                        'asins_asin_listing_sync_requests.sync_id'
                    )
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) =>
                            $q->where('asins_asin_listing_sync.marketplace_id', $mp)
                    )
                    ->select('asins_asin_listing_sync_requests.*')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('sync_id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('attempts')
                    ->sortable(),

                Tables\Columns\TextColumn::make('run_after')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->actions([]) // никаких row actions
            ->bulkActions([
                Tables\Actions\BulkAction::make('clear')
                    ->label('Clear')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        \Filament\Forms\Components\Select::make('period')
                            ->label('Период')
                            ->options([
                                'all' => 'Все',
                                '3d'  => 'Старше 3 дней',
                            ])
                            ->default('3d')
                            ->required(),
                    ])
                    ->action(function (array $data): void {

                        $q = AsinListingSyncRequest::query()
                            ->join(
                                'asins_asin_listing_sync',
                                'asins_asin_listing_sync.id',
                                '=',
                                'asins_asin_listing_sync_requests.sync_id'
                            )
                            ->when(
                                session('active_marketplace'),
                                fn ($qq, $mp) =>
                                    $qq->where('asins_asin_listing_sync.marketplace_id', $mp)
                            );

                        if (($data['period'] ?? '3d') === '3d') {
                            $q->where(
                                'asins_asin_listing_sync_requests.created_at',
                                '<',
                                now()->subDays(3)
                            );
                        }

                        $ids = (clone $q)
                            ->select('asins_asin_listing_sync_requests.id')
                            ->pluck('id');

                        if ($ids->isNotEmpty()) {
                            \Illuminate\Support\Facades\DB::table('asins_asin_listing_sync_requests')
                                ->whereIn('id', $ids)
                                ->delete();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinListingSyncRequests::route('/'),
        ];
    }

    /**
     * 🔐 Только нужные Shield-права
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
            'delete_any',
        ];
    }
}
