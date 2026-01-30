<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\OrdersItemsSync;
use App\Filament\Resources\Logs\OrdersItemsSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class OrdersItemsSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = OrdersItemsSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'Orders Items Sync';
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?int $navigationSort = 60;

    private const SCREEN_DEBUG = true;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                session('active_marketplace'),
                fn (Builder $q, $mp) => $q->where('marketplace_id', (int) $mp)
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)

            ->headerActions([
                Tables\Actions\Action::make('debugSql')
                    ->label('DEBUG SQL')
                    ->color('danger')
                    ->icon('heroicon-o-bug-ant')
                    ->action(function () {
                        $query = OrdersItemsSync::query();

                        Notification::make()
                            ->title('🔴 BASE QUERY SQL')
                            ->danger()
                            ->body(
                                '<pre>'
                                . e($query->toSql()) . "\n"
                                . e(json_encode($query->getBindings()))
                                . '</pre>'
                            )
                            ->send();
                    }),
            ])

            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('amazon_order_id')->sortable(),
                Tables\Columns\TextColumn::make('status')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])

            ->filters([
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date (HARD DEBUG)')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('to'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {

                        if (self::SCREEN_DEBUG) {
                            Notification::make()
                                ->title('🔴 FILTER HIT')
                                ->danger()
                                ->body(
                                    '<pre>'
                                    . e(json_encode($data, JSON_PRETTY_PRINT))
                                    . '</pre>'
                                )
                                ->send();
                        }

                        // 💣 ЖЁСТКИЙ ТЕСТ
                        // ЕСЛИ ЭТО НЕ ОЧИСТИТ ТАБЛИЦУ — ФИЛЬТР ИГНОРИРУЕТСЯ
                        $q->whereRaw('1 = 0');

                        if (self::SCREEN_DEBUG) {
                            Notification::make()
                                ->title('🔴 FINAL SQL')
                                ->danger()
                                ->body(
                                    '<pre>'
                                    . e($q->toSql()) . "\n"
                                    . e(json_encode($q->getBindings()))
                                    . '</pre>'
                                )
                                ->send();
                        }

                        return $q;
                    }),
            ])

            ->actions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdersItemsSyncs::route('/'),
        ];
    }

    public static function getPermissionPrefixes(): array
    {
        return ['view_any', 'delete_any'];
    }
}
