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
     * БАЗОВЫЙ QUERY (ТОЛЬКО ТО, ЧТО НЕ ЗАВИСИТ ОТ ФИЛЬТРОВ)
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        // marketplace — это OK, это session
        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        return $q;
    }

    /**
     * 🔥 ЖЁСТКИЙ DEBUG — ВСЕГДА ВИДЕН
     */
    protected static function debugState(array $filterData = []): array
    {
        return [
            'REQUEST' => [
                'method' => request()->method(),
                'is_livewire' => request()->hasHeader('X-Livewire'),
                'url' => request()->fullUrl(),
            ],
            'FILTER_DATA_FROM_FILAMENT' => $filterData,
            'SESSION' => [
                'active_marketplace' => session('active_marketplace'),
            ],
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                /**
                 * 🔥 DEBUG BLOCK — ВСЕГДА
                 */
                Tables\Columns\TextColumn::make('__DEBUG__')
                    ->label('⚠ DEBUG (Filament filters / SQL)')
                    ->state(function () {
                        // SQL без фильтров (база)
                        $base = static::getEloquentQuery();

                        return json_encode([
                            'BASE_SQL' => [
                                'query' => $base->toSql(),
                                'bindings' => $base->getBindings(),
                            ],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    })
                    ->wrap()
                    ->extraAttributes([
                        'class' => 'font-mono text-xs text-red-700 bg-gray-100',
                    ]),

                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('amazon_order_id')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('status')->sortable()->badge(),
                Tables\Columns\TextColumn::make('attempts')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('started_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable(),
            ])

            ->filters([
                /**
                 * ✅ ЕДИНСТВЕННО ПРАВИЛЬНЫЙ ФИЛЬТР ПО ДАТЕ
                 * (Livewire state → query)
                 */
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')->label('From'),
                            Forms\Components\DatePicker::make('to')->label('To'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data) {
                        /**
                         * 🔥 DEBUG ПРЯМО В SQL
                         */
                        logger()->debug('[OrdersItemsSync] filter data', $data);

                        if (!empty($data['from'])) {
                            $query->where(
                                'created_at',
                                '>=',
                                Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()
                            );
                        }

                        if (!empty($data['to'])) {
                            $query->where(
                                'created_at',
                                '<=',
                                Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay()
                            );
                        }
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
