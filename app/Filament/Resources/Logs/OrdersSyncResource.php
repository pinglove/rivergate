<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\OrdersSync;
use App\Filament\Resources\Logs\OrdersSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class OrdersSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = OrdersSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'Orders Sync';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    /**
     * 🔥 ЭТАЛОН: базовый query
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        if ($mp = session('active_marketplace')) {
            $q->where('marketplace_id', (int) $mp);
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            // свежие сверху
            ->defaultSort('id', 'desc')

            // 50 строк по умолчанию
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'     => 'gray',
                        'processing'  => 'warning',
                        'completed'   => 'success',
                        'success'     => 'success',
                        'failed'      => 'danger',
                        'error'       => 'danger',
                        'skipped'     => 'secondary',
                        default       => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('from_date')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('to_date')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_forced')
                    ->boolean()
                    ->label('Forced'),

                Tables\Columns\TextColumn::make('orders_fetched')
                    ->label('Fetched')
                    ->sortable(),

                Tables\Columns\TextColumn::make('imported_count')
                    ->label('Imported')
                    ->sortable(),

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
                    ->sortable(),
            ])

            ->filters([
                /**
                 * Created date
                 */
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from')->label('From'),
                            Forms\Components\DatePicker::make('to')->label('To'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['from'])) {
                            $query->where(
                                'created_at',
                                '>=',
                                Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()
                            );
                        }

                        if (!empty($data['to'])) {
                            $query->where(
                                'created_at',
                                '<=',
                                Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay()
                            );
                        }
                    }),

                /**
                 * Status
                 */
                Tables\Filters\Filter::make('status')
                    ->label('Status')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending'     => 'Pending',
                                'processing'  => 'Processing',
                                'completed'   => 'Completed',
                                'success'     => 'Success',
                                'failed'      => 'Failed',
                                'error'       => 'Error',
                                'skipped'     => 'Skipped',
                            ])
                            ->placeholder('Any'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['status'])) {
                            $query->where('status', $data['status']);
                        }
                    }),
            ])

            // ❌ нет row actions
            ->actions([])

            // ❌ НЕТ bulkActions — нет чекбоксов и Clear
            ;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdersSyncs::route('/'),
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
