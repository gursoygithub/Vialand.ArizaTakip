<?php

namespace App\Providers;

use App\Ldap\AttributeHandler;
use App\Listeners\UserAuthenticated;
use Carbon\Carbon;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use LdapRecord\Laravel\Events\Import\Synchronized;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Carbon::setLocale('tr');
        setlocale(LC_TIME, 'tr_TR.UTF-8', 'tr_TR', 'tr');

        Table::$defaultDateTimeDisplayFormat = 'd F Y - H:i';
        Table::$defaultDateDisplayFormat = 'd F Y';
        Table::$defaultCurrency = 'TRY';

        // Pagination defaults — applied to every Filament table.
        // Filament 3.x doesn't expose static props for these, so we register
        // a global Configurable callback. Override per-resource only when the
        // table genuinely needs a different page size; document the reason
        // in a comment there.
        Table::configureUsing(function (Table $table): void {
            $table
                ->paginationPageOptions([5, 10, 25, 50])
                ->defaultPaginationPageOption(10);
        });

        Infolist::$defaultCurrency = 'TRY';
        Infolist::$defaultDateTimeDisplayFormat = 'd F Y - H:i';
        Infolist::$defaultDateDisplayFormat = 'd F Y';

        Number::useLocale('tr');
        Number::useCurrency('TRY');

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        // LDAP sync → save custom attributes
        Event::listen(Synchronized::class, function (Synchronized $event) {
            $handler = new AttributeHandler();
            $handler->handle($event->object, $event->model);
            $event->model->save();
        });

        Event::listen(
            'Illuminate\Auth\Events\Authenticated',
            UserAuthenticated::class
        );
    }
}
