<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\OrdersItemsSync;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Logs\OrdersItemsSyncResource\Pages;

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

    public static function table(Table $table): Table
    {
        return $table

            /* =========================================================
             * QUERY
             * ========================================================= */
            ->query(
                OrdersItemsSync::query()
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) => $q->where('marketplace_id', (int) $mp)
                    )
            )

            /* =========================================================
             * SORT + PAGINATION
             * ========================================================= */
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            /* =========================================================
             * HEADER ACTIONS
             * ========================================================= */
            ->headerActions([
                Tables\Actions\Action::make('clearLogs')
                    ->icon('heroicon-o-trash')
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Clear logs')
                    ->requiresConfirmation()
                    ->modalHeading('Clear logs')
                    ->modalDescription('This will permanently delete log records.')
                    ->form([
                        Forms\Components\Select::make('period')
                            ->label('Delete logs')
                            ->options([
                                '3d'  => 'Older than 3 days',
                                '7d'  => 'Older than 7 days',
                                '30d' => 'Older than 30 days',
                                'all' => 'All logs',
                            ])
                            ->default('7d')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $q = OrdersItemsSync::query()
                            ->when(
                                session('active_marketplace'),
                                fn (Builder $qq, $mp) => $qq->where('marketplace_id', (int) $mp)
                            );

                        match ($data['period']) {
                            '3d'  => $q->where('created_at', '<', now()->subDays(3)),
                            '7d'  => $q->where('created_at', '<', now()->subDays(7)),
                            '30d' => $q->where('created_at', '<', now()->subDays(30)),
                            default => null,
                        };

                        $q->delete();
                    }),
            ])

            /* =========================================================
             * COLUMNS
             * ========================================================= */
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amazon_order_id')
                    ->label('Amazon Order')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'    => 'gray',
                        'processing' => 'warning',
                        'completed'  => 'success',
                        'failed'     => 'danger',
                        'skipped'    => 'secondary',
                        default      => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('attempts')
                    ->sortable(),

                Tables\Columns\IconColumn::make('items_imported')
                    ->label('Imported')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\TextColumn::make('run_after')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('finished_at')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->toggleable(),
            ])

            /* =========================================================
             * FILTERS — ЧЁТКО И РАБОЧЕ
             * ========================================================= */
            ->filters([

                /* ---------- STATUS ---------- */
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'    => 'Pending',
                        'processing' => 'Processing',
                        'completed'  => 'Completed',
                        'failed'     => 'Failed',
                        'skipped'    => 'Skipped',
                    ]),

                /* ---------- AMAZON ORDER ---------- */
                Tables\Filters\Filter::make('amazon_order')
                    ->label('Amazon Order')
                    ->form([
                        Forms\Components\TextInput::make('order_id')
                            ->label('Amazon Order ID'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q->when(
                            filled($data['order_id'] ?? null),
                            fn (Builder $qq) => $qq->where(
                                'amazon_order_id',
                                'like',
                                '%' . $data['order_id'] . '%'
                            )
                        );
                    }),

                /* ---------- ATTEMPTS RANGE ---------- */
                Tables\Filters\Filter::make('attempts_range')
                    ->label('Attempts range')
                    ->form([
                        Forms\Components\TextInput::make('min')
                            ->numeric()
                            ->label('From'),
                        Forms\Components\TextInput::make('max')
                            ->numeric()
                            ->label('To'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(filled($data['min'] ?? null), fn (Builder $qq) => $qq->where('attempts', '>=', (int) $data['min']))
                            ->when(filled($data['max'] ?? null), fn (Builder $qq) => $qq->where('attempts', '<=', (int) $data['max']));
                    }),

                /* ---------- IMPORTED ---------- */
                Tables\Filters\SelectFilter::make('imported')
                    ->label('Imported')
                    ->options([
                        'yes' => 'Yes',
                        'no'  => 'No',
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'yes' => $q->where('items_imported', '>', 0),
                            'no'  => $q->whereNull('items_imported'),
                            default => $q,
                        };
                    }),

                /* ---------- CREATED DATE RANGE ---------- */
                Tables\Filters\Filter::make('created_period')
                    ->label('Created period')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('From date'),
                        Forms\Components\DatePicker::make('to')
                            ->label('To date'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $qq) => $qq->whereDate('created_at', '>=', $data['from'])
                            )
                            ->when(
                                filled($data['to'] ?? null),
                                fn (Builder $qq) => $qq->whereDate('created_at', '<=', $data['to'])
                            );
                    }),
            ])

            ->actions([]); // никаких row actions
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdersItemsSyncs::route('/'),
        ];
    }

    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
            'delete_any',
        ];
    }
}
чвчч