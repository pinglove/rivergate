<?php

namespace App\Filament\Resources\Amazon\Asins;

use App\Models\Amazon\Asins\Asin;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Builder;

class AsinResource extends Resource
{
    protected static ?string $model = Asin::class;

    protected static ?string $navigationGroup = 'Amazon';
    protected static ?string $navigationLabel = 'ASINs';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Глобальный query ресурса — ВСЕГДА в разрезе marketplace
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('marketplace_id', (int) session('active_marketplace'));
    }

    /**
     * Описание таблицы (НЕ страницы)
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->size(48)
                    ->square()
                    ->defaultImageUrl(
                        'data:image/svg+xml;utf8,' . rawurlencode(
                            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48">
                                <rect width="100%" height="100%" fill="#f3f4f6"/>
                                <path d="M14 30l6-6 4 4 6-6 4 4" stroke="#9ca3af" stroke-width="2" fill="none"/>
                            </svg>'
                        )
                    ),


                TextColumn::make('asin')
                    ->label('ASIN')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label('Title')
                    ->limit(80)
                    ->searchable(),
            ])
            ->defaultSort('asin');
    }

}
