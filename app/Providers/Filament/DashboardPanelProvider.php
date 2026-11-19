<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use Filament\Http\Middleware\Authenticate;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\SpatieLaravelTranslatablePlugin;

class DashboardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('dashboard')
            ->path('')
            //->login()
//            ->profile(EditProfile::class)
//            ->userMenuItems([
//                'profile' => \Filament\Navigation\MenuItem::make()
//                    ->label(fn (): string => auth()->user()->name),
//            ])
            ->colors([
                'primary' => Color::Blue,
            ])
            ->databaseNotifications()
            // 5s polling so the bell + browser notification fire promptly.
            // The actual desktop notification is dispatched from the
            // notifications-js partial (see renderHook below) by watching
            // the badge count for increases.
            ->databaseNotificationsPolling('5s')
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.fcm-init')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.session-expired')->render(),
            )
            // Order follows daily use: work first, set-up and system last.
            ->navigationGroups([
                __('ui.requests'),
                __('ui.reports'),
                __('ui.request_settings'),
                __('ui.user_management'),
                __('ui.system'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                \App\Filament\Widgets\RequestOverviewStats::class,
                \App\Filament\Widgets\MyRequestQueue::class,
                \App\Filament\Widgets\RequestsByStatusChart::class,
                \App\Filament\Widgets\OpenRequestsByTypeChart::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
//                SpatieLaravelTranslatablePlugin::make()
//                ->defaultLocales(['fr', 'ar']),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
