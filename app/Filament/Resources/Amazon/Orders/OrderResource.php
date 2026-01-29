<?php

namespace App\Filament\Resources\Amazon\Orders;

use App\Models\Orders\Order;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\Amazon\Orders\OrderResource\Pages;

// 🔐 Shield
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class OrderResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationGroup = 'Amazon';
    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) => $q->where('marketplace_id', $mp)
                    )
                    ->orderByDesc('purchase_date')
            )
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
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Purchased')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_total_amount')
                    ->label('Amount')
                    ->money(fn ($record) => $record->order_total_currency),

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
                        'Pending' => 'Pending',
                        'Unshipped' => 'Unshipped',
                        'PartiallyShipped' => 'PartiallyShipped',
                        'Shipped' => 'Shipped',
                        'Canceled' => 'Canceled',
                    ]),

                Tables\Filters\SelectFilter::make('ship_country')
                    ->label('Country')
                    ->options(fn () =>
                        Order::query()
                            ->whereNotNull('ship_country')
                            ->distinct()
                            ->orderBy('ship_country')
                            ->pluck('ship_country', 'ship_country')
                            ->toArray()
                    ),

                Tables\Filters\Filter::make('purchase_date')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $query
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $date) => $q->whereDate('purchase_date', '>=', $date)
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn ($q, $date) => $q->whereDate('purchase_date', '<=', $date)
                            );
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

    /**
     * 🔐 Read-only resource
     */
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
