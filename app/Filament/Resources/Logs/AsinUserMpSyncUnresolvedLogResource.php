<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinUserMpSyncUnresolvedLog;
use App\Filament\Resources\Logs\AsinUserMpSyncUnresolvedLogResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AsinUserMpSyncUnresolvedLogResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinUserMpSyncUnresolvedLog::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Sync / Unresolved Logs';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    /**
     * БАЗОВЫЙ QUERY (ТОЛЬКО ОБЩИЕ УСЛОВИЯ)
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()
            ->join(
                'asins_user_mp_sync_unresolved',
                'asins_user_mp_sync_unresolved.id',
                '=',
                'asins_user_mp_sync_unresolved_logs.unresolved_id'
            )
            ->select('asins_user_mp_sync_unresolved_logs.*');

        if ($mp = session('active_marketplace')) {
            $q->where('asins_user_mp_sync_unresolved.marketplace_id', (int) $mp);
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            // свежие логи сверху
            ->defaultSort('id', 'desc')

            // 🔢 ЭТАЛОН: 50 строк по умолчанию
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('unresolved_id')
                    ->label('Unresolved ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('step')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'     => 'gray',
                        'processing'  => 'warning',
                        'completed'   => 'success',
                        'resolved'    => 'success', // ✅ ДОБАВЛЕНО
                        'success'     => 'success',
                        'failed'      => 'danger',
                        'error'       => 'danger',
                        'skipped'     => 'secondary',
                        default       => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('payload')
                    ->label('Payload')
                    ->limit(60)
                    ->toggleable(),
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
                                'asins_user_mp_sync_unresolved_logs.created_at',
                                '>=',
                                Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()
                            );
                        }

                        if (!empty($data['to'])) {
                            $query->where(
                                'asins_user_mp_sync_unresolved_logs.created_at',
                                '<=',
                                Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay()
                            );
                        }
                    }),

                /**
                 * Unresolved ID
                 */
                Tables\Filters\Filter::make('unresolved_id')
                    ->label('Unresolved ID')
                    ->form([
                        Forms\Components\TextInput::make('unresolved_id'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['unresolved_id'])) {
                            $query->where(
                                'asins_user_mp_sync_unresolved_logs.unresolved_id',
                                (int) $data['unresolved_id']
                            );
                        }
                    }),

                /**
                 * Step
                 */
                Tables\Filters\Filter::make('step')
                    ->label('Step')
                    ->form([
                        Forms\Components\TextInput::make('step'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['step'])) {
                            $query->where(
                                'asins_user_mp_sync_unresolved_logs.step',
                                $data['step']
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

                        $q = AsinUserMpSyncUnresolvedLog::query()
                            ->join(
                                'asins_user_mp_sync_unresolved',
                                'asins_user_mp_sync_unresolved.id',
                                '=',
                                'asins_user_mp_sync_unresolved_logs.unresolved_id'
                            );

                        if ($mp = session('active_marketplace')) {
                            $q->where(
                                'asins_user_mp_sync_unresolved.marketplace_id',
                                (int) $mp
                            );
                        }

                        if (($data['period'] ?? '3d') === '3d') {
                            $q->where(
                                'asins_user_mp_sync_unresolved_logs.created_at',
                                '<',
                                now()->subDays(3)
                            );
                        }

                        $ids = (clone $q)
                            ->select('asins_user_mp_sync_unresolved_logs.id')
                            ->pluck('id');

                        if ($ids->isNotEmpty()) {
                            DB::table('asins_user_mp_sync_unresolved_logs')
                                ->whereIn('id', $ids)
                                ->delete();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinUserMpSyncUnresolvedLogs::route('/'),
        ];
    }

    public static function getPermissionPrefixes(): array
    {
        return ['view_any', 'delete_any'];
    }
}
