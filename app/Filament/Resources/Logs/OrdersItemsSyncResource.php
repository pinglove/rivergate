<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\OrdersItemsSync;
use App\Filament\Resources\Logs\OrdersItemsSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class OrdersItemsSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = OrdersItemsSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'Orders Items Sync';
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?int $navigationSort = 60;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    /**
     * 🔥 ЕДИНСТВЕННОЕ МЕСТО, ГДЕ ФИЛАМЕНТ УВАЖАЕТ WHERE
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        // marketplace
        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        // 🔴 DATE FILTER (REAL, FINAL)
        $filters = request()->input('tableFilters.created_period');

        if (is_array($filters)) {
            $from = $filters['from'] ?? null;
            $to   = $filters['to'] ?? null;

            if ($from) {
                $q->where(
                    'created_at',
                    '>=',
                    Carbon::createFromFormat('Y-m-d', $from)->startOfDay()
                );
            }

            if ($to) {
                $q->where(
                    'created_at',
                    '<=',
                    Carbon::createFromFormat('Y-m-d', $to)->endOfDay()
                );
            }
        }

        return $q;
    }

    /**
     * DEBUG helper: enabled only when ?debug=1 AND APP_DEBUG=true (so you don't leak info in prod accidentally)
     */
    protected static function debugEnabled(): bool
    {
        return (bool) config('app.debug') && request()->boolean('debug');
    }

    protected static function debugPayload(): array
    {
        return [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'is_livewire' => request()->hasHeader('X-Livewire'),
            'tableFilters_created_period' => request()->input('tableFilters.created_period'),
            'all_tableFilters' => request()->input('tableFilters'),
            'active_marketplace_session' => session('active_marketplace'),
        ];
    }

    protected static function debugSql(): array
    {
        // Build the exact same query and dump its SQL/bindings.
        // NOTE: This is the *builder SQL* (with bindings), not the final DB executed SQL string.
        $q = static::getEloquentQuery();

        return [
            'sql' => $q->toSql(),
            'bindings' => $q->getBindings(),
        ];
    }

    public static function table(Table $table): Table
    {
        $debug = static::debugEnabled();

        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns(array_values(array_filter([
                // =========================
                // DEBUG "COLUMNS" (top of table)
                // =========================
                $debug ? Tables\Columns\TextColumn::make('___debug_request')
                    ->label('DEBUG: Request / Filters received')
                    ->state(function () {
                        return json_encode(self::debugPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    : null,

                $debug ? Tables\Columns\TextColumn::make('___debug_sql')
                    ->label('DEBUG: SQL (builder) + bindings')
                    ->state(function () {
                        return json_encode(self::debugSql(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    : null,

                // =========================
                // REAL COLUMNS
                // =========================
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('amazon_order_id')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('status')->sortable()->badge(),
                Tables\Columns\TextColumn::make('attempts')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable(),
            ])))

            ->filters([
                // UI ФИЛЬТР (БЕЗ query!)
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')->label('From'),
                            Forms\Components\DatePicker::make('to')->label('To'),
                        ]),
                    ]),
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
