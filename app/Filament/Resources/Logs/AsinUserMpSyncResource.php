<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinUserMpSync;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Logs\AsinUserMpSyncResource\Pages;

// 🔴 ВАЖНО: ЭТОГО НЕ ХВАТАЛО
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class AsinUserMpSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinUserMpSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Sync';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                AsinUserMpSync::query()
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) => $q->where('marketplace_id', $mp)
                    )
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user_id'),
                Tables\Columns\TextColumn::make('marketplace_id'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('attempts'),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
            ])
            ->actions([])
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
                    ->action(function (array $data) {

                        $q = AsinUserMpSync::query()
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
            'index' => Pages\ListAsinUserMpSyncs::route('/'),
        ];
    }

    /**
     * 🔐 Shield будет генерить ТОЛЬКО эти permissions
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
            'delete_any',
        ];
    }
    
    public static function canCreate(): bool
    {
        return false;
    }

}
