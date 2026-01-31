<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Settings\Marketplaces;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\UserMenuItem;
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
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\View\PanelsRenderHook;

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

            // 🔌 Plugins
            ->plugins([
                FilamentShieldPlugin::make(),
            ])

            // 🎨 Colors
            ->colors([
                'primary' => Color::Amber,
            ])

            // 📂 Navigation groups
            ->navigationGroups([
                'Amazon',
                'Logs',
                'Local settings',
                'System',
                'Filament Shield',
            ])

            // 📑 Resources / Pages / Widgets
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

            /**
             * 🔹 ПУНКТЫ МЕНЮ (идут ПОСЛЕ переключения темы)
             */
            ->userMenuItems([
                UserMenuItem::make()
                    ->label(fn () => 'Marketplaces (' . session('active_marketplace_code', '—') . ')')
                    ->icon('heroicon-o-globe-alt')
                    ->url(fn () => Marketplaces::getUrl()),

                UserMenuItem::make()
                    ->label(fn () => 'Language (' . strtoupper(app()->getLocale()) . ')')
                    ->icon('heroicon-o-language')
                    ->url('#'),

                // ❗ НЕ ТРОГАЕМ
                UserMenuItem::make()
                    ->label('Active marketplaces')
                    ->icon('heroicon-o-briefcase')
                    ->url(fn () => Marketplaces::getUrl()),
            ])

            /**
             * 🔽 DROPDOWN’Ы
             * Marketplace + Language
             * СРАЗУ ПОСЛЕ Profile (до theme switcher)
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                fn () => view('filament.hooks.marketplace-switcher'),
                10
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                fn () => view('filament.hooks.lang-switcher'),
                20
            )

            // 🧠 Middleware
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

            // 🔐 Auth
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
