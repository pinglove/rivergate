<?php

namespace App\Filament\Resources\Amazon\Orders;

use App\Models\Orders\Order;
use App\Filament\Resources\Amazon\Orders\OrderResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationGroup = 'Amazon';
    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?int $navigationSort = 30;

    /**
     * 🔥 БАЗОВЫЙ QUERY
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        return $q->orderByDesc('purchase_date');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('amazon_order_id')
                    ->label('Amazon Order')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('merchant_order_id')
                    ->label('Merchant')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order_status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->color(function (string $state) {
                        return match ($state) {
                            'Pending'               => 'gray',
                            'Unshipped'             => 'warning',
                            'PartiallyShipped'      => 'info',
                            'Shipped'               => 'success',
                            'Canceled'              => 'danger',
                            'Unfulfillable'         => 'danger',
                            'InvoiceUnconfirmed'    => 'secondary',
                            default                 => 'secondary',
                        };
                    }),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Purchased')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_total_amount')
                    ->label('Amount')
                    ->money(function ($record) {
                        return $record->order_total_currency;
                    }),

                Tables\Columns\TextColumn::make('ship_country')
                    ->label('Country')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_prime')
                    ->boolean()
                    ->label('Prime'),

                Tables\Columns\IconColumn::make('is_business_order')
                    ->boolean()
                    ->label('B2B'),

                Tables\Columns\IconColumn::make('is_replacement_order')
                    ->boolean()
                    ->label('Replacement'),

                Tables\Columns\TextColumn::make('fulfillment_channel')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('marketplace_id')
                    ->label('MP')
                    ->sortable(),
            ])

            ->filters([
                Tables\Filters\SelectFilter::make('order_status')
                    ->options([
                        'Pending'            => 'Pending',
                        'Unshipped'          => 'Unshipped',
                        'PartiallyShipped'   => 'PartiallyShipped',
                        'Shipped'            => 'Shipped',
                        'Canceled'           => 'Canceled',
                        'Unfulfillable'      => 'Unfulfillable',
                        'InvoiceUnconfirmed' => 'InvoiceUnconfirmed',
                    ]),

                Tables\Filters\SelectFilter::make('ship_country')
                    ->label('Country')
                    ->options(function () {
                        return Order::query()
                            ->whereNotNull('ship_country')
                            ->distinct()
                            ->orderBy('ship_country')
                            ->pluck('ship_country', 'ship_country')
                            ->toArray();
                    }),

                Tables\Filters\Filter::make('purchase_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['from'])) {
                            $query->whereDate('purchase_date', '>=', $data['from']);
                        }

                        if (!empty($data['to'])) {
                            $query->whereDate('purchase_date', '<=', $data['to']);
                        }
                    }),

                Tables\Filters\TernaryFilter::make('is_prime')->label('Prime'),
                Tables\Filters\TernaryFilter::make('is_business_order')->label('B2B'),
                Tables\Filters\TernaryFilter::make('is_replacement_order')->label('Replacement'),
            ])

            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
        ];
    }

    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
