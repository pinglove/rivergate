<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinListingSyncImport;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use App\Filament\Resources\Logs\AsinListingSyncImportResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class AsinListingSyncImportResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinListingSyncImport::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Listing Imports';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 23;

    /* 🔐 Только super_admin */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                AsinListingSyncImport::query()
                    ->join(
                        'asins_asin_listing_sync',
                        'asins_asin_listing_sync.id',
                        '=',
                        'asins_asin_listing_sync_imports.sync_id'
                    )
                    ->join(
                        'marketplaces',
                        'marketplaces.id',
                        '=',
                        'asins_asin_listing_sync.marketplace_id'
                    )
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) => $q->where('marketplaces.id', $mp)
                    )
                    ->select('asins_asin_listing_sync_imports.*')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('sync_id')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
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

                        $mp = session('active_marketplace');

                        $q = AsinListingSyncImport::query()
                            ->join(
                                'asins_asin_listing_sync',
                                'asins_asin_listing_sync.id',
                                '=',
                                'asins_asin_listing_sync_imports.sync_id'
                            )
                            ->join(
                                'marketplaces',
                                'marketplaces.id',
                                '=',
                                'asins_asin_listing_sync.marketplace_id'
                            )
                            ->when(
                                $mp,
                                fn ($qq) => $qq->where('marketplaces.id', $mp)
                            );

                        if (($data['period'] ?? '3d') === '3d') {
                            $q->where(
                                'asins_asin_listing_sync_imports.created_at',
                                '<',
                                now()->subDays(3)
                            );
                        }

                        // удаляем ТОЛЬКО imports
                        $ids = (clone $q)
                            ->select('asins_asin_listing_sync_imports.id')
                            ->pluck('id');

                        if ($ids->isNotEmpty()) {
                            DB::table('asins_asin_listing_sync_imports')
                                ->whereIn('id', $ids)
                                ->delete();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinListingSyncImports::route('/'),
        ];
    }

    /**
     * 🔐 Ограничиваем Shield-права ТОЛЬКО нужными
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
            'delete_any',
        ];
    }
}
