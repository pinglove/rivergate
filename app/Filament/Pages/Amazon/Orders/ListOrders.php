<?php

namespace App\Filament\Pages\Amazon\Orders;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Illuminate\Database\Eloquent\Builder;

use App\Models\Amazon\Orders\Order;
use Filament\Forms\Components\DatePicker;

class ListOrders extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Amazon';
    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.amazon.orders.list-orders';

    protected function getTableQuery(): Builder
    {
        $marketplaceId = session('active_marketplace');

        return Order::query()
            ->when(
                $marketplaceId,
                fn ($q) => $q->where('marketplace_id', $marketplaceId)
            )
            ->orderByDesc('purchase_date');
    }


    protected function getTableColumns(): array
    {
        return [
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
        ];
    }

    protected function getTableFilters(): array
    {
        return [
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
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('to')->label('To'),
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
        ];
    }

    protected function getTableActions(): array
    {
        return [];
    }

    protected function getTableBulkActions(): array
    {
        return [];
    }
}
