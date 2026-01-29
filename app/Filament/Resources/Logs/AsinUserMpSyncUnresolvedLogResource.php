<?php

namespace App\Filament\Resources\Logs;

use App\Models\Logs\AsinUserMpSyncUnresolvedLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use App\Filament\Resources\Logs\AsinUserMpSyncUnresolvedLogResource\Pages;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;

class AsinUserMpSyncUnresolvedLogResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = AsinUserMpSyncUnresolvedLog::class;

    protected static ?string $navigationGroup = 'Logs';
    protected static ?string $navigationLabel = 'ASIN Sync/Unresolved Logs';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 11;

    /* 🔐 Только super_admin */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                AsinUserMpSyncUnresolvedLog::query()
                    ->join(
                        'asins_user_mp_sync_unresolved',
                        'asins_user_mp_sync_unresolved.id',
                        '=',
                        'asins_user_mp_sync_unresolved_logs.unresolved_id'
                    )
                    ->when(
                        session('active_marketplace'),
                        fn (Builder $q, $mp) =>
                            $q->where('asins_user_mp_sync_unresolved.marketplace_id', $mp)
                    )
                    ->select('asins_user_mp_sync_unresolved_logs.*')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),

                Tables\Columns\TextColumn::make('unresolved_id')
                    ->label('Unresolved ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('step')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime(),

                Tables\Columns\TextColumn::make('payload')
                    ->label('Payload')
                    ->limit(60)
                    ->toggleable(),
            ])
            ->actions([]) // никаких row actions
            ->bulkActions([
                Tables\Actions\BulkAction::make('clear')
                    ->label('Clear')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        \Filament\Forms\Components\Select::make('period')
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
                            )
                            ->when(
                                session('active_marketplace'),
                                fn ($qq, $mp) =>
                                    $qq->where('asins_user_mp_sync_unresolved.marketplace_id', $mp)
                            );

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

    /**
     * 🔐 Только нужные Shield-права
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view_any',
            'delete_any',
        ];
    }
}
