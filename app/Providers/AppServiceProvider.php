<?php

namespace App\Providers;

use App\Ldap\AttributeHandler;
use App\Listeners\UserAuthenticated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use LdapRecord\Laravel\Events\Import\Synchronized;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
//        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
//            $switch
//                ->locales(['fr', 'ar']); // also accepts a closure
//        });

        Table::$defaultDateTimeDisplayFormat = 'd F Y - H:i';
        Table::$defaultDateDisplayFormat = 'd F Y';
        Table::$defaultCurrency = 'TRY';

        Infolist::$defaultCurrency = 'TRY';
        Infolist::$defaultDateTimeDisplayFormat = 'd F Y - H:i';
        Infolist::$defaultDateDisplayFormat = 'd F Y';

        Number::useLocale('tr');
        Number::useCurrency('TRY');

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        // LDAP senkronizasyonu sonrası custom attribute'ları kaydet
        Event::listen(Synchronized::class, function (Synchronized $event) {
            $handler = new AttributeHandler();
            $handler->handle($event->object, $event->model);
            $event->model->save();
        });

        Event::listen(
            'Illuminate\Auth\Events\Authenticated',
            USerAuthenticated::class
        );
    }
}
