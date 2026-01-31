<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinListingSyncRequestPayload;
use App\Filament\Resources\Logs\AsinListingSyncRequestPayloadResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class AsinListingSyncRequestPayloadResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinListingSyncRequestPayload::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Listing Sync / Payloads';
    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';
    protected static ?int $navigationSort = 22;

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
                'asins_asin_listing_sync_request_payloads.request_id'
            )
            ->select('asins_asin_listing_sync_request_payloads.*');

        if ($mp = session('active_marketplace')) {
            $q->where('asins_asin_listing_sync.marketplace_id', (int) $mp);
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
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('request_id')
                    ->label('Request ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payload')
                    ->label('Payload')
                    ->limit(80)
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
                                'asins_asin_listing_sync_request_payloads.created_at',
                                '>=',
                                Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay()
                            );
                        }

                        if (!empty($data['to'])) {
                            $query->where(
                                'asins_asin_listing_sync_request_payloads.created_at',
                                '<=',
                                Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay()
                            );
                        }
                    }),

                /**
                 * Request ID
                 */
                Tables\Filters\Filter::make('request_id')
                    ->label('Request ID')
                    ->form([
                        Forms\Components\TextInput::make('request_id'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['request_id'])) {
                            $query->where(
                                'asins_asin_listing_sync_request_payloads.request_id',
                                (int) $data['request_id']
                            );
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
            'index' => Pages\ListAsinListingSyncRequestPayloads::route('/'),
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
