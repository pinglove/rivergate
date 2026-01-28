<?php

namespace App\Filament\Resources\Amazon;

use App\Models\Amazon\RefreshToken;
use Filament\Resources\Resource;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Tables;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;

use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;

use App\Filament\Pages\Amazon\ListRefreshTokens;
use App\Filament\Pages\Amazon\CreateRefreshToken;
use App\Filament\Pages\Amazon\EditRefreshToken;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RefreshTokenResource extends Resource
{
    protected static ?string $model = RefreshToken::class;

    protected static ?string $navigationGroup = 'Local settings';
    protected static ?string $navigationLabel = 'Refresh Tokens';
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([

            Hidden::make('marketplace_id')
                ->default(fn () => (int) session('active_marketplace'))
                ->required(),

            Section::make('Seller')
                ->schema([
                    TextInput::make('amazon_seller_id')
                        ->label('Amazon Seller ID')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('lwa_refresh_token')
                        ->label('LWA Refresh Token')
                        ->password()
                        ->revealable()
                        ->required()
                        ->helperText('Seller OAuth refresh token (LWA).'),
                ]),

            Section::make('LWA Application')
                ->schema([
                    TextInput::make('lwa_client_id')
                        ->label('LWA Client ID')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('lwa_client_secret')
                        ->label('LWA Client Secret')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->collapsed(),

            Section::make('AWS IAM')
                ->schema([
                    TextInput::make('aws_access_key_id')
                        ->label('AWS Access Key ID')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('aws_secret_access_key')
                        ->label('AWS Secret Access Key')
                        ->password()
                        ->revealable()
                        ->required(),

                    TextInput::make('aws_role_arn')
                        ->label('AWS Role ARN')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('sp_api_region')
                        ->label('SP-API Region')
                        ->default('eu')
                        ->required()
                        ->maxLength(10),
                ])
                ->collapsed(),

            Section::make('Meta')
                ->schema([
                    Select::make('auth_type')
                        ->label('Auth Type')
                        ->options([
                            'manual' => 'Manual',
                            'oauth'  => 'OAuth',
                        ])
                        ->default('manual')
                        ->required(),

                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'active'  => 'Active',
                            'revoked' => 'Revoked',
                        ])
                        ->default('active')
                        ->required(),

                    DateTimePicker::make('last_used_at')
                        ->label('Last Used At')
                        ->disabled(),
                ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')
                    ->label('User')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('amazon_seller_id')
                    ->label('Seller ID')
                    ->searchable(),

                BadgeColumn::make('auth_type')
                    ->label('Auth')
                    ->colors([
                        'secondary' => 'manual',
                        'success'   => 'oauth',
                    ]),

                BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'danger'  => 'revoked',
                    ]),

                TextColumn::make('last_used_at')
                    ->label('Last Used')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListRefreshTokens::route('/'),
            'create' => CreateRefreshToken::route('/create'),
            'edit'   => EditRefreshToken::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->where('marketplace_id', (int) session('active_marketplace'));
    }

    public static function canCreate(): bool
    {
        return ! RefreshToken::query()
            ->where('user_id', auth()->id())
            ->where('marketplace_id', (int) session('active_marketplace'))
            ->exists();
    }

    public static function getNavigationLabel(): string
    {
        $marketplaceId = (int) session('active_marketplace');

        if (! $marketplaceId) {
            return 'Refresh Token';
        }

        $code = DB::table('marketplaces')
            ->where('id', $marketplaceId)
            ->value('code');

        return 'Refresh Token (' . ($code ?? $marketplaceId) . ')';
    }
}
