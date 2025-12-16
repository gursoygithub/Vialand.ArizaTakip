<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
use Illuminate\Support\Number;

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
    }
}
