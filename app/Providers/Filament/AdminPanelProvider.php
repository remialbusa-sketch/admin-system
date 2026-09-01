<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandName('Admin System')
            ->favicon('favicon.ico')
            ->colors([
                // Palette mirrors resources/css/app.css (daisyUI theme) so the
                // Filament panel and the main dashboard share one visual system.
                // Shade 600 is the surface shade Filament buttons use in light
                // mode, so it is anchored to the exact daisyUI token. Tints
                // (50-400) sit on Filament's lightness curve at the token's
                // hue, while the anchor zone (500-900) is compressed around
                // shade 600 so Filament's WCAG resolver keeps solid brand
                // buttons with white text in both modes instead of falling
                // back to a tonal style (950 is lifted for the same reason).
                'primary' => [
                    50 => '#f2f8ff',
                    100 => '#e1efff',
                    200 => '#c6e1ff',
                    300 => '#a0cbff',
                    400 => '#72acff',
                    500 => '#3774eb',
                    600 => '#1e59cd',
                    700 => '#1043a5',
                    800 => '#0b3380',
                    900 => '#092864',
                    950 => '#11264f',
                ],
                'gray' => Color::Slate,
                'info' => [
                    50 => '#f0f9ff',
                    100 => '#ddf2ff',
                    200 => '#bde5ff',
                    300 => '#8dd3ff',
                    400 => '#4cb8ff',
                    500 => '#00a1f9',
                    600 => '#007bc0',
                    700 => '#006db6',
                    800 => '#005993',
                    900 => '#004a77',
                    950 => '#002b49',
                ],
                'success' => [
                    50 => '#f0fbf4',
                    100 => '#ddf6e6',
                    200 => '#beedd0',
                    300 => '#8de0b0',
                    400 => '#45cb8a',
                    500 => '#00b76f',
                    600 => '#15915c',
                    700 => '#008044',
                    800 => '#006738',
                    900 => '#005631',
                    950 => '#00321a',
                ],
                'warning' => [
                    50 => '#fef6ee',
                    100 => '#fdebd8',
                    200 => '#fcd9b3',
                    300 => '#f7bf7c',
                    400 => '#eb9c2b',
                    500 => '#da8200',
                    600 => '#d18e35',
                    700 => '#9c5300',
                    800 => '#7e4400',
                    900 => '#673a00',
                    950 => '#3e2000',
                ],
                'danger' => [
                    50 => '#fff4f3',
                    100 => '#ffe7e4',
                    200 => '#ffd0cc',
                    300 => '#ffafa9',
                    400 => '#ff8580',
                    500 => '#f06764',
                    600 => '#cf4042',
                    700 => '#ad3b3b',
                    800 => '#8c3231',
                    900 => '#722c2b',
                    950 => '#451716',
                ],
            ])
            ->font('Manrope')
            ->darkMode(isForced: false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                \App\Filament\Widgets\QuickLinksWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
