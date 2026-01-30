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
     * Marketplace filter — ТОЛЬКО здесь
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
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

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

            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'    => 'Pending',
                        'processing' => 'Processing',
                        'completed'  => 'Completed',
                        'failed'     => 'Failed',
                        'skipped'    => 'Skipped',
                    ]),

                Tables\Filters\Filter::make('amazon_order')
                    ->label('Amazon Order')
                    ->form([
                        Forms\Components\TextInput::make('order_id')
                            ->label('Contains'),
                    ])
                    ->query(fn (Builder $q, array $data) =>
                        $q->when(
                            filled($data['order_id'] ?? null),
                            fn (Builder $qq) => $qq->where('amazon_order_id', 'like', '%' . $data['order_id'] . '%')
                        )
                    ),

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

                /**
                 * ✅ CREATED DATE RANGE — реально рабочий (Y-m-d + whereBetween)
                 */
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')->label('Created from'),
                            Forms\Components\DatePicker::make('to')->label('Created to'),
                        ]),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        $fromRaw = $data['from'] ?? null;
                        $toRaw   = $data['to'] ?? null;

                        // DatePicker обычно отдаёт 'Y-m-d'. Делаем максимально строгий парс.
                        $from = filled($fromRaw)
                            ? Carbon::createFromFormat('Y-m-d', (string) $fromRaw)->startOfDay()
                            : null;

                        $to = filled($toRaw)
                            ? Carbon::createFromFormat('Y-m-d', (string) $toRaw)->endOfDay()
                            : null;

                        // обе даты — самый надёжный вариант
                        if ($from && $to) {
                            return $q->whereBetween('created_at', [
                                $from->toDateTimeString(),
                                $to->toDateTimeString(),
                            ]);
                        }

                        // одна из дат
                        return $q
                            ->when($from, fn (Builder $qq) => $qq->where('created_at', '>=', $from->toDateTimeString()))
                            ->when($to, fn (Builder $qq) => $qq->where('created_at', '<=', $to->toDateTimeString()));
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
