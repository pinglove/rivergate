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

        // 🔴 DATE FILTER
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
     * ⚠️ ЖЁСТКИЙ DEBUG — ВСЕГДА
     */
    protected static function debugData(): array
    {
        $q = static::getEloquentQuery();

        return [
            'REQUEST' => [
                'full_url' => request()->fullUrl(),
                'method' => request()->method(),
                'is_livewire' => request()->hasHeader('X-Livewire'),
            ],
            'TABLE_FILTERS' => request()->input('tableFilters'),
            'CREATED_PERIOD' => request()->input('tableFilters.created_period'),
            'SESSION' => [
                'active_marketplace' => session('active_marketplace'),
            ],
            'SQL' => [
                'query' => $q->toSql(),
                'bindings' => $q->getBindings(),
            ],
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->deferFilters(false)
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                /**
                 * 🔥 DEBUG BLOCK (ALWAYS VISIBLE)
                 */
                Tables\Columns\TextColumn::make('__DEBUG__')
                    ->label('⚠ DEBUG (request / filters / SQL)')
                    ->state(fn () => json_encode(
                        self::debugData(),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                    ))
                    ->wrap()
                    ->extraAttributes([
                        'class' => 'font-mono text-xs text-red-600 bg-gray-100',
                    ]),

                /**
                 * REAL COLUMNS
                 */
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('amazon_order_id')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('status')->sortable()->badge(),
                Tables\Columns\TextColumn::make('attempts')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable(),
            ])

            ->filters([
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
