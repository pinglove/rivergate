<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinListingSync;
use App\Filament\Resources\Logs\AsinListingSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class AsinListingSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinListingSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Listing Sync';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    /**
     * 🔥 ЭТАЛОН: базовый query ТОЛЬКО здесь
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
            // свежие логи сверху
            ->defaultSort('id', 'desc')

            // 50 строк по умолчанию
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('asin_id')
                    ->label('ASIN ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(function (string $state) {
                        return match ($state) {
                            'pending'     => 'gray',
                            'processing'  => 'warning',
                            'completed'   => 'success',
                            'resolved'    => 'success',
                            'success'     => 'success',
                            'failed'      => 'danger',
                            'error'       => 'danger',
                            'skipped'     => 'secondary',
                            default       => 'secondary',
                        };
                    }),

                Tables\Columns\TextColumn::make('pipeline')
                    ->sortable()
                    ->badge()
                    ->color(function (string $state) {
                        return match ($state) {
                            'pending'     => 'gray',
                            'processing'  => 'warning',
                            'completed'   => 'success',
                            'resolved'    => 'success',
                            'success'     => 'success',
                            'failed'      => 'danger',
                            'error'       => 'danger',
                            'skipped'     => 'secondary',
                            default       => 'secondary',
                        };
                    }),

                Tables\Columns\TextColumn::make('run_after')
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
                                'resolved'    => 'Resolved',
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
                 * ASIN ID
                 */
                Tables\Filters\Filter::make('asin_id')
                    ->label('ASIN ID')
                    ->form([
                        Forms\Components\TextInput::make('asin_id'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['asin_id'])) {
                            $query->where(
                                'asin_id',
                                'like',
                                '%' . trim($data['asin_id']) . '%'
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

                        $q = AsinListingSync::query();

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
            'index' => Pages\ListAsinListingSyncs::route('/'),
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
