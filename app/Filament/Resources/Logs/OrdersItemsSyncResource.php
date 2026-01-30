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

        /**
         * marketplace
         */
        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        /**
         * DATE FILTER (created_at)
         */
        $period = request()->input('tableFilters.created_period');

        if (is_array($period)) {
            if (!empty($period['from'])) {
                $q->where(
                    'created_at',
                    '>=',
                    Carbon::createFromFormat('Y-m-d', $period['from'])->startOfDay()
                );
            }

            if (!empty($period['to'])) {
                $q->where(
                    'created_at',
                    '<=',
                    Carbon::createFromFormat('Y-m-d', $period['to'])->endOfDay()
                );
            }
        }

        /**
         * AMAZON ORDER ID FILTER
         * (ТОЧНО как date — без value)
         */
        $orderFilter = request()->input('tableFilters.amazon_order_id');

        if (is_array($orderFilter) && !empty($orderFilter['order'])) {
            $q->where(
                'amazon_order_id',
                'like',
                '%' . trim($orderFilter['order']) . '%'
            );
        }

        /**
         * STATUS FILTER
         */
        $statusFilter = request()->input('tableFilters.status');

        if (is_array($statusFilter) && !empty($statusFilter['status'])) {
            $q->where('status', $statusFilter['status']);
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->deferFilters(false) // 🔥 ВАЖНО: иначе фильтры НЕ применяются

            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('amazon_order_id')
                    ->label('Amazon Order')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'     => 'gray',
                        'processing'  => 'warning',
                        'success',
                        'completed'   => 'success',
                        'failed',
                        'error'       => 'danger',
                        'skipped'     => 'secondary',
                        default       => 'secondary',
                    }),

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

                Tables\Filters\Filter::make('amazon_order_id')
                    ->label('Amazon Order ID')
                    ->form([
                        Forms\Components\TextInput::make('order'),
                    ]),

                Tables\Filters\Filter::make('status')
                    ->label('Status')
                    ->form([
                        Forms\Components\Select::make('status')->options([
                            'pending'     => 'Pending',
                            'processing'  => 'Processing',
                            'success'     => 'Success',
                            'completed'   => 'Completed',
                            'failed'      => 'Failed',
                            'error'       => 'Error',
                            'skipped'     => 'Skipped',
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
