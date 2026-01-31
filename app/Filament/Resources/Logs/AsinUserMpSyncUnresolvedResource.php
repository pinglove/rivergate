<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinUserMpSyncUnresolved;
use App\Filament\Resources\Logs\AsinUserMpSyncUnresolvedResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class AsinUserMpSyncUnresolvedResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinUserMpSyncUnresolved::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Sync / Unresolved';
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

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
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)

            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('seller_sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('product_id')
                    ->label('Product ID')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('product_id_type')
                    ->label('ID Type')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('title')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'     => 'gray',
                        'processing'  => 'warning',
                        'completed'   => 'success',
                        'resolved'    => 'success',
                        'success'     => 'success',
                        'failed'      => 'danger',
                        'error'       => 'danger',
                        'skipped'     => 'secondary',
                        default       => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('attempts')->sortable(),

                Tables\Columns\TextColumn::make('run_after')
                    ->dateTime()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('asin_id')
                    ->label('ASIN ID')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])

            ->filters([
                Tables\Filters\Filter::make('created_period')
                    ->label('Created date')
                    ->form([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('from'),
                            Forms\Components\DatePicker::make('to'),
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

                Tables\Filters\Filter::make('status')
                    ->label('Status')
                    ->form([
                        Forms\Components\Select::make('status')->options([
                            'pending'     => 'Pending',
                            'processing'  => 'Processing',
                            'completed'   => 'Completed',
                            'resolved'    => 'Resolved',
                            'success'     => 'Success',
                            'failed'      => 'Failed',
                            'error'       => 'Error',
                            'skipped'     => 'Skipped',
                        ]),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['status'])) {
                            $query->where('status', $data['status']);
                        }
                    }),

                Tables\Filters\Filter::make('seller_sku')
                    ->label('SKU')
                    ->form([
                        Forms\Components\TextInput::make('seller_sku'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (!empty($data['seller_sku'])) {
                            $query->where(
                                'seller_sku',
                                'like',
                                '%' . trim($data['seller_sku']) . '%'
                            );
                        }
                    }),
            ])

            // ❌ НЕТ row actions
            ->actions([])

            // ❌ НЕТ bulkActions
            ;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAsinUserMpSyncUnresolveds::route('/'),
        ];
    }

    public static function getPermissionPrefixes(): array
    {
        return ['view_any', 'delete_any'];
    }
}
