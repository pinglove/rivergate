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
     * Marketplace filter — делаем на уровне EloquentQuery,
     * так Filament filters работают корректно и стабильно.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                session('active_marketplace'),
                fn (Builder $q, $mp) => $q->where('marketplace_id', (int) $mp)
            );
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

                        match ($data['period']) {
                            '3d'  => $q->where('created_at', '<', now()->subDays(3)),
                            '7d'  => $q->where('created_at', '<', now()->subDays(7)),
                            '30d' => $q->where('created_at', '<', now()->subDays(30)),
                            default => null, // all
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
             * FILTERS — PROD
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
                        $value = trim((string) ($data['order_id'] ?? ''));

                        return $q->when(
                            $value !== '',
                            fn (Builder $qq) => $qq->where('amazon_order_id', 'like', '%' . $value . '%')
                        );
                    }),

                /* ---------- ATTEMPTS RANGE (STABLE) ---------- */
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
                        $min = $data['min'] ?? null;
                        $max = $data['max'] ?? null;

                        return $q
                            ->when(
                                filled($min),
                                fn (Builder $qq) => $qq->where('attempts', '>=', (int) $min)
                            )
                            ->when(
                                filled($max),
                                fn (Builder $qq) => $qq->where('attempts', '<=', (int) $max)
                            );
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
                            'yes' => $q->whereNotNull('items_imported')->where('items_imported', '>', 0),
                            'no'  => $q->where(fn ($qq) =>
                                $qq->whereNull('items_imported')
                                   ->orWhere('items_imported', '=', 0)
                            ),
                            default => $q,
                        };
                    }),

                /* ---------- CREATED DATE RANGE (WORKING) ---------- */
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')
                                ->label('Created from'),
                            Forms\Components\DatePicker::make('to')
                                ->label('Created to'),
                        ]),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $qq) => $qq->where(
                                    'created_at',
                                    '>=',
                                    Carbon::parse($data['from'])->startOfDay()
                                )
                            )
                            ->when(
                                filled($data['to'] ?? null),
                                fn (Builder $qq) => $qq->where(
                                    'created_at',
                                    '<=',
                                    Carbon::parse($data['to'])->endOfDay()
                                )
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
