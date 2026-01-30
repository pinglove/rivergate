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
             * HEADER ACTIONS (TOP BAR)
             * ========================================================= */
            ->headerActions([
                Tables\Actions\Action::make('clearLogs')
                    ->label('') // только иконка
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

                        $period = $data['period'] ?? '7d';

                        if ($period === '3d') {
                            $q->where('created_at', '<', now()->subDays(3));
                        } elseif ($period === '7d') {
                            $q->where('created_at', '<', now()->subDays(7));
                        } elseif ($period === '30d') {
                            $q->where('created_at', '<', now()->subDays(30));
                        } // 'all' => без условий

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
             * FILTERS (FIXED: no $v / no wrong signatures)
             * ========================================================= */
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'    => 'Pending',
                        'processing' => 'Processing',
                        'completed'  => 'Completed',
                        'failed'     => 'Failed',
                        'skipped'    => 'Skipped',
                    ]),

                Tables\Filters\Filter::make('amazon_order_id')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('Amazon Order ID'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return $q->when(
                            filled($value),
                            fn (Builder $qq) => $qq->where('amazon_order_id', 'like', "%{$value}%")
                        );
                    }),

                Tables\Filters\Filter::make('attempts')
                    ->form([
                        Forms\Components\TextInput::make('from')->numeric(),
                        Forms\Components\TextInput::make('to')->numeric(),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(filled($data['from'] ?? null), fn (Builder $qq) => $qq->where('attempts', '>=', (int) $data['from']))
                            ->when(filled($data['to'] ?? null), fn (Builder $qq) => $qq->where('attempts', '<=', (int) $data['to']));
                    }),

                Tables\Filters\SelectFilter::make('items_imported')
                    ->label('Imported')
                    ->options([
                        'yes' => 'Yes',
                        'no'  => 'No',
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return $q
                            ->when($value === 'yes', fn (Builder $qq) => $qq->where('items_imported', '>', 0))
                            ->when($value === 'no', fn (Builder $qq) => $qq->whereNull('items_imported'));
                    }),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('to'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(filled($data['from'] ?? null), fn (Builder $qq) => $qq->whereDate('created_at', '>=', $data['from']))
                            ->when(filled($data['to'] ?? null), fn (Builder $qq) => $qq->whereDate('created_at', '<=', $data['to']));
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
