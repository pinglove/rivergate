<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinListingSyncRequest;
use App\Filament\Resources\Logs\AsinListingSyncRequestResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AsinListingSyncRequestResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinListingSyncRequest::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Listing Sync / Requests';
    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    /**
     * 🔥 ЭТАЛОН: базовый query
     */
    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()
            ->join(
                'asins_asin_listing_sync',
                'asins_asin_listing_sync.id',
                '=',
                'asins_asin_listing_sync_requests.sync_id'
            )
            ->select('asins_asin_listing_sync_requests.*');

        if ($mp = session('active_marketplace')) {
            $q->where('asins_asin_listing_sync.marketplace_id', (int) $mp);
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('sync_id')->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(function (string $state) {
                        return match ($state) {
                            'pending'     => 'gray',
                            'processing'  => 'warning',
                            'completed'   => 'success',
                            'resolved'    => 'success', // ✅
                            'success'     => 'success',
                            'failed'      => 'danger',
                            'error'       => 'danger',
                            'skipped'     => 'secondary',
                            default       => 'secondary',
                        };
                    }),

                Tables\Columns\TextColumn::make('attempts')->sortable(),

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
                                'asins_asin_listing_sync_requests.created_at',
                                '>=',
                                Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()
                            );
                        }

                        if (!empty($data['to'])) {
                            $query->where(
                                'asins_asin_listing_sync_requests.created_at',
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
                            $query->where(
                                'asins_asin_listing_sync_requests.status',
                                $data['status']
                            );
                        }
                    }),

                /**
                 * Sync ID
                 */
                Tables\Filters\Filter::make('sync_id')
                    ->label('Sync ID')
                    ->form([
                        Forms\Components\TextInput::make('sync_id'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['sync_id'])) {
                            $query->where(
                                'asins_asin_listing_sync_requests.sync_id',
                                (int) $data['sync_id']
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

                        $q = AsinListingSyncRequest::query()
                            ->join(
                                'asins_asin_listing_sync',
                                'asins_asin_listing_sync.id',
                                '=',
                                'asins_asin_listing_sync_requests.sync_id'
                            );

                        if ($mp = session('active_marketplace')) {
                            $q->where('asins_asin_listing_sync.marketplace_id', (int) $mp);
                        }

                        if (($data['period'] ?? '3d') === '3d') {
                            $q->where(
                                'asins_asin_listing_sync_requests.created_at',
                                '<',
                                now()->subDays(3)
                            );
                        }

                        $ids = (clone $q)
                            ->select('asins_asin_listing_sync_requests.id')
                            ->pluck('id');

                        if ($ids->isNotEmpty()) {
                            DB::table('asins_asin_listing_sync_requests')
                                ->whereIn('id', $ids)
                                ->delete();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinListingSyncRequests::route('/'),
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
