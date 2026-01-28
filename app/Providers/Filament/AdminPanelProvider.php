<?php

namespace App\Providers\Filament;

use App\Models\UserMarketplace;
use App\Filament\Pages\Settings\Marketplaces;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('dashboard')
            ->login()
            ->profile()

            ->colors([
                'primary' => Color::Amber,
            ])

            // ⬅ SIDEBAR
            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\\Filament\\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\\Filament\\Pages'
            )
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\\Filament\\Widgets'
            )
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])

            /*
            // 👤 USER MENU (GLOBAL)
            ->userMenuItems(array_merge(

                    
                // ---------- SETTINGS ----------
                [
                    MenuItem::make()
                        ->label('Settings')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->url(fn () => Marketplaces::getUrl()),

                    MenuItem::make()->label('—'),

                    MenuItem::make()
                        ->label(fn () => 'Marketplace: ' . strtoupper(session('active_marketplace', '—')))
                        ->icon('heroicon-o-globe-alt'),
                ],

                // ---------- MARKETPLACE SWITCH ----------
                    
                collect(array_keys(config('amazon_marketplaces')))
                    ->map(fn (string $code) =>
                        MenuItem::make()
                            ->label($code)
                            ->url(fn () => route('marketplace.switch', $code))
                            ->visible(fn () =>
                                auth()->check()
                                && session('active_marketplace') !== $code
                                && UserMarketplace::query()
                                    ->where('user_id', auth()->id())
                                    ->where('marketplace_id', $code)
                                    ->where('is_enabled', true)
                                    ->exists()
                            )
                    )
                    ->all(),

                // ---------- LANGUAGE ----------
                [
                    MenuItem::make()->label('—'),

                    MenuItem::make()
                        ->label(fn () => 'Language: ' . strtoupper(session('locale', 'en')))
                        ->icon('heroicon-o-language'),

                    MenuItem::make()
                        ->label('EN')
                        ->url(fn () => route('locale.switch', 'en'))
                        ->visible(fn () => session('locale', 'en') !== 'en'),

                    MenuItem::make()
                        ->label('FR')
                        ->url(fn () => route('locale.switch', 'fr'))
                        ->visible(fn () => session('locale', 'en') !== 'fr'),

                    MenuItem::make()
                        ->label('DE')
                        ->url(fn () => route('locale.switch', 'de'))
                        ->visible(fn () => session('locale', 'en') !== 'de'),
                ]
            ))
             * 
             */

            // 🧱 Middleware
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,

                \App\Http\Middleware\SetLocale::class,
                \App\Http\Middleware\SetActiveMarketplace::class,

                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
