<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinUserMpSync;
use App\Filament\Resources\Logs\AsinUserMpSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * 🔥 ЭТАЛОН: базовый query ТОЛЬКО здесь
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            // свежие логи сверху
            ->defaultSort('id', 'desc')

            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user_id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('marketplace_id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('attempts')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
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

                        $q = AsinUserMpSync::query();

                        if ($mp = session('active_marketplace')) {
                            $q->where('marketplace_id', (int) $mp);
                        }

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
     * 🔐 Shield permissions — как в эталоне
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
