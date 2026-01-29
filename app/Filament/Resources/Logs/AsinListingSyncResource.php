<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinListingSync;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\Logs\AsinListingSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class AsinListingSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinListingSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Listing Sync';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?int $navigationSort = 20;

    /* 🔐 Только super_admin */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                AsinListingSync::query()
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) => $q->where('marketplace_id', $mp)
                    )
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('asin_id')
                    ->label('ASIN ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('pipeline')
                    ->badge(),

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

                        $q = AsinListingSync::query()
                            ->when(
                                session('active_marketplace'),
                                fn ($qq, $mp) => $qq->where('marketplace_id', $mp)
                            );

                        if (($data['period'] ?? '3d') === '3d') {
                            $q->where('created_at', '<', now()->subDays(3));
                        }

                        $q->delete();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinListingSyncs::route('/'),
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
