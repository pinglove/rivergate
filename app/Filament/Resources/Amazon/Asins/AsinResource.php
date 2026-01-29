<?php

namespace App\Filament\Resources\Amazon\Asins;

use App\Models\Amazon\Asins\Asin;
use App\Models\Amazon\Review\ReviewRequestSetting;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

use App\Filament\Resources\Amazon\Asins\AsinResource\Pages;

// 🔐 Shield
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class AsinResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Asin::class;

    protected static ?string $navigationGroup = 'Amazon';
    protected static ?string $navigationLabel = 'ASINs';
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?int $navigationSort = 25;

    /**
     * Глобальный query ресурса — всегда user + marketplace
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->where('marketplace_id', (int) session('active_marketplace'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('review_enabled')
                    ->label('Review')
                    ->getStateUsing(fn (Asin $record) =>
                        ReviewRequestSetting::query()
                            ->where('user_id', auth()->id())
                            ->where('marketplace_id', (int) session('active_marketplace'))
                            ->where('asin_id', $record->id)
                            ->value('is_enabled') ?? false
                    )
                    ->updateStateUsing(function (Asin $record, bool $state) {
                        ReviewRequestSetting::updateOrCreate(
                            [
                                'user_id'        => auth()->id(),
                                'marketplace_id' => (int) session('active_marketplace'),
                                'asin_id'        => $record->id,
                            ],
                            [
                                'is_enabled'   => $state,
                                'delay_days'   => 7,
                                'process_hour' => 2,
                            ]
                        );
                    }),

                TextColumn::make('review_delay')
                    ->label('Delay')
                    ->state(fn (Asin $record) =>
                        ReviewRequestSetting::query()
                            ->where('user_id', auth()->id())
                            ->where('marketplace_id', (int) session('active_marketplace'))
                            ->where('asin_id', $record->id)
                            ->value('delay_days')
                    )
                    ->formatStateUsing(fn ($state) => $state ? "{$state} days" : '—'),

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

            /* ================= FILTERS ================= */

            ->filters([
                // Review ON / OFF
                Tables\Filters\TernaryFilter::make('review_enabled_filter')
                    ->label('Review enabled')
                    ->queries(
                        true: fn (Builder $q) =>
                            $q->whereExists(function ($sub) {
                                $sub->selectRaw(1)
                                    ->from('review_request_settings')
                                    ->whereColumn('review_request_settings.asin_id', 'asins.id')
                                    ->where('review_request_settings.user_id', auth()->id())
                                    ->where('review_request_settings.marketplace_id', session('active_marketplace'))
                                    ->where('review_request_settings.is_enabled', true);
                            }),

                        false: fn (Builder $q) =>
                            $q->whereExists(function ($sub) {
                                $sub->selectRaw(1)
                                    ->from('review_request_settings')
                                    ->whereColumn('review_request_settings.asin_id', 'asins.id')
                                    ->where('review_request_settings.user_id', auth()->id())
                                    ->where('review_request_settings.marketplace_id', session('active_marketplace'))
                                    ->where('review_request_settings.is_enabled', false);
                            }),

                        blank: fn (Builder $q) => $q
                    ),

                // Review delay
                Tables\Filters\SelectFilter::make('review_delay_filter')
                    ->label('Review delay')
                    ->options([
                        3  => '3 days',
                        5  => '5 days',
                        7  => '7 days',
                        14 => '14 days',
                        21 => '21 days',
                        30 => '30 days',
                    ])
                    ->query(function (Builder $q, array $data) {

                        if (! isset($data['value'])) {
                            return;
                        }

                        $delay = (int) $data['value'];

                        $q->whereExists(function ($sub) use ($delay) {
                            $sub->selectRaw(1)
                                ->from('review_request_settings')
                                ->whereColumn('review_request_settings.asin_id', 'asins.id')
                                ->where('review_request_settings.user_id', auth()->id())
                                ->where('review_request_settings.marketplace_id', session('active_marketplace'))
                                ->where('review_request_settings.delay_days', $delay);
                        });
                    }),


                // Has / no review settings
                Tables\Filters\TernaryFilter::make('has_review_settings')
                    ->label('Has review settings')
                    ->queries(
                        true: fn (Builder $q) =>
                            $q->whereExists(function ($sub) {
                                $sub->selectRaw(1)
                                    ->from('review_request_settings')
                                    ->whereColumn('review_request_settings.asin_id', 'asins.id')
                                    ->where('review_request_settings.user_id', auth()->id())
                                    ->where('review_request_settings.marketplace_id', session('active_marketplace'));
                            }),

                        false: fn (Builder $q) =>
                            $q->whereNotExists(function ($sub) {
                                $sub->selectRaw(1)
                                    ->from('review_request_settings')
                                    ->whereColumn('review_request_settings.asin_id', 'asins.id')
                                    ->where('review_request_settings.user_id', auth()->id())
                                    ->where('review_request_settings.marketplace_id', session('active_marketplace'));
                            }),

                        blank: fn (Builder $q) => $q
                    ),
            ])

            ->defaultSort('asin')
            ->actions([])

            /* ================= BULK ACTIONS ================= */

            ->bulkActions([
                BulkAction::make('enableReview')
                    ->label('Enable review')
                    ->action(fn (Collection $records) =>
                        $records->each(fn (Asin $asin) =>
                            ReviewRequestSetting::updateOrCreate(
                                [
                                    'user_id'        => auth()->id(),
                                    'marketplace_id' => (int) session('active_marketplace'),
                                    'asin_id'        => $asin->id,
                                ],
                                [
                                    'is_enabled' => true,
                                    'delay_days' => 5,
                                ]
                            )
                        )
                    ),

                BulkAction::make('disableReview')
                    ->label('Disable review')
                    ->action(fn (Collection $records) =>
                        $records->each(fn (Asin $asin) =>
                            ReviewRequestSetting::updateOrCreate(
                                [
                                    'user_id'        => auth()->id(),
                                    'marketplace_id' => (int) session('active_marketplace'),
                                    'asin_id'        => $asin->id,
                                ],
                                [
                                    'is_enabled' => false,
                                ]
                            )
                        )
                    ),

                BulkAction::make('setDelay')
                    ->label('Set delay')
                    ->form([
                        Select::make('delay_days')
                            ->label('Delay days')
                            ->options([
                                3  => '3 days',
                                5  => '5 days',
                                7  => '7 days',
                                14 => '14 days',
                                21 => '21 days',
                                30 => '30 days',
                            ])
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data) {
                        foreach ($records as $asin) {
                            ReviewRequestSetting::updateOrCreate(
                                [
                                    'user_id'        => auth()->id(),
                                    'marketplace_id' => (int) session('active_marketplace'),
                                    'asin_id'        => $asin->id,
                                ],
                                [
                                    'delay_days' => (int) $data['delay_days'],
                                ]
                            );
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsins::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * 🔐 Убираем зоопарк прав — только просмотр
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
        ];
    }
}
