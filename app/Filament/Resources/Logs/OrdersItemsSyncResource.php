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
     * ✅ Фильтрация по дате ДОЛЖНА быть здесь.
     * И читаем tableFilters именно так, как в твоём рабочем примере.
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        // marketplace
        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        // ✅ CREATED DATE FILTER (REAL, FINAL) — как у тебя работало
        $filters = request()->input('tableFilters.created_period');

        if (is_array($filters)) {
            $from = $filters['from'] ?? null;
            $to   = $filters['to'] ?? null;

            if ($from) {
                $q->where(
                    'created_at',
                    '>=',
                    Carbon::createFromFormat('Y-m-d', (string) $from)->startOfDay()
                );
            }

            if ($to) {
                $q->where(
                    'created_at',
                    '<=',
                    Carbon::createFromFormat('Y-m-d', (string) $to)->endOfDay()
                );
            }
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table

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

                        match ($data['period'] ?? '7d') {
                            '3d'  => $q->where('created_at', '<', now()->subDays(3)),
                            '7d'  => $q->where('created_at', '<', now()->subDays(7)),
                            '30d' => $q->where('created_at', '<', now()->subDays(30)),
                            default => null, // all
                        };

                        $q->delete();
                    }),
            ])

            /* =========================================================
             * COLUMNS — сортировка по всем
             * ========================================================= */
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amazon_order_id')
                    ->label('Amazon Order')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'    => 'gray',
                        'processing' => 'warning',
                        'completed'  => 'success',
                        'failed'     => 'danger',
                        'skipped'    => 'secondary',
                        default      => 'gray',
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->sortable(),

                Tables\Columns\IconColumn::make('items_imported')
                    ->label('Imported')
                    ->boolean()
                    ->sortable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\TextColumn::make('run_after')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])

            /* =========================================================
             * FILTERS
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
                            ->label('Contains'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q->when(
                            filled($data['order_id'] ?? null),
                            fn (Builder $qq) => $qq->where('amazon_order_id', 'like', '%' . $data['order_id'] . '%')
                        );
                    }),

                /* ---------- ATTEMPTS RANGE ---------- */
                Tables\Filters\Filter::make('attempts_range')
                    ->label('Attempts range')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('min')
                                ->label('Attempts from')
                                ->numeric()
                                ->minValue(0),
                            Forms\Components\TextInput::make('max')
                                ->label('Attempts to')
                                ->numeric()
                                ->minValue(0),
                        ]),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                filled($data['min'] ?? null),
                                fn (Builder $qq) => $qq->where('attempts', '>=', (int) $data['min'])
                            )
                            ->when(
                                filled($data['max'] ?? null),
                                fn (Builder $qq) => $qq->where('attempts', '<=', (int) $data['max'])
                            );
                    }),

                /* ---------- IMPORTED ---------- */
                Tables\Filters\SelectFilter::make('imported')
                    ->label('Imported')
                    ->options([
                        'yes' => 'Yes',
                        'no'  => 'No',
                    ])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'yes' => $q->where('items_imported', '>', 0),
                        'no'  => $q->where(fn ($qq) =>
                            $qq->whereNull('items_imported')
                                ->orWhere('items_imported', '=', 0)
                        ),
                        default => $q,
                    }),

                /* ---------- CREATED DATE (UI only; logic in getEloquentQuery) ---------- */
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')->label('Created from'),
                            Forms\Components\DatePicker::make('to')->label('Created to'),
                        ]),
                    ]),
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
