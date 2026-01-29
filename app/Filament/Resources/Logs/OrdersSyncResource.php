<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\OrdersSync;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\Logs\OrdersSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class OrdersSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = OrdersSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'Orders Sync';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 50;

    /* 🔐 Только super_admin */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                OrdersSync::query()
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) => $q->where('marketplace_id', $mp)
                    )
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('from_date')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('to_date')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_forced')
                    ->boolean()
                    ->label('Forced'),

                Tables\Columns\TextColumn::make('orders_fetched')
                    ->label('Fetched')
                    ->sortable(),

                Tables\Columns\TextColumn::make('imported_count')
                    ->label('Imported')
                    ->sortable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('finished_at')
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

                        $q = OrdersSync::query()
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
            'index' => Pages\ListOrdersSyncs::route('/'),
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
