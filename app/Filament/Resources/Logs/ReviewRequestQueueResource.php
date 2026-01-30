<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\ReviewRequestQueue;
use App\Filament\Resources\Logs\ReviewRequestQueueResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class ReviewRequestQueueResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = ReviewRequestQueue::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'Review Request Queue';
    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static ?int $navigationSort = 70;

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

                Tables\Columns\TextColumn::make('amazon_order_id')
                    ->label('Order ID')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('asin')
                    ->label('ASIN')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(function (string $state) {
                        return match ($state) {
                            'pending'     => 'gray',
                            'processing'  => 'warning',
                            'completed'   => 'success',
                            'success'     => 'success',
                            'failed'      => 'danger',
                            'error'       => 'danger',
                            'skipped'     => 'secondary',
                            default       => 'secondary',
                        };
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->sortable(),

                Tables\Columns\TextColumn::make('run_after')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('requested_at')
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

                /**
                 * Amazon Order ID
                 */
                Tables\Filters\Filter::make('amazon_order_id')
                    ->label('Order ID')
                    ->form([
                        Forms\Components\TextInput::make('amazon_order_id'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['amazon_order_id'])) {
                            $query->where(
                                'amazon_order_id',
                                'like',
                                '%' . trim($data['amazon_order_id']) . '%'
                            );
                        }
                    }),
            ])

            ->actions([])

            ->bulkActions([
                Tables\Actions\BulkAction::make('clear')
                    ->label('Clear')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Select::make('period')
                            ->label('Период')
                            ->options([
                                'all' => 'Все',
                                '3d'  => 'Старше 3 дней',
                            ])
                            ->default('3d')
                            ->required(),
                    ])
                    ->action(function (array $data): void {

                        $q = ReviewRequestQueue::query();

                        if ($mp = session('active_marketplace')) {
                            $q->where('marketplace_id', (int) $mp);
                        }

                        if (($data['period'] ?? '3d') === '3d') {
                            $q->where('created_at', '<', now()->subDays(3));
                        }

                        $q->delete();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviewRequestQueues::route('/'),
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
