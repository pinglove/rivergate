<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinUserMpSync;
use App\Filament\Resources\Logs\AsinUserMpSyncResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class AsinUserMpSyncResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinUserMpSync::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Sync';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    /**
     * БАЗОВЫЙ QUERY (ТОЛЬКО ОБЩИЕ УСЛОВИЯ)
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

            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user_id')->sortable(),
                Tables\Columns\TextColumn::make('marketplace_id')->sortable(),

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

                Tables\Columns\TextColumn::make('attempts')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])

            ->filters([
                /**
                 * Created at period
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

            // ❌ НЕТ actions
            ->actions([])

            // ❌ НЕТ bulkActions — значит:
            //   - нет чекбоксов
            //   - нет массовых действий
            //   - нет кнопки Clear
            ;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinUserMpSyncs::route('/'),
        ];
    }

    public static function getPermissionPrefixes(): array
    {
        return ['view_any', 'delete_any'];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
