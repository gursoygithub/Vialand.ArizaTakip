<?php

namespace App\Providers;

use App\Ldap\AttributeHandler;
use App\Listeners\UserAuthenticated;
use App\Models\Group;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Observers\TicketObserver;
use App\Policies\GroupPolicy;
use App\Policies\SlaPolicyPolicy;
use App\Policies\TicketPolicy;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Repositories\TicketRepository;
use App\Services\SlaService;
use App\Services\TicketService;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use LdapRecord\Laravel\Events\Import\Synchronized;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SlaService::class);
        $this->app->singleton(TicketService::class);
        $this->app->bind(TicketRepositoryInterface::class, TicketRepository::class);
    }

    public function boot(): void
    {
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

        // Register observers
        Ticket::observe(TicketObserver::class);

        // Register policies explicitly (Shield also discovers them automatically)
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);
        Gate::policy(SlaPolicy::class, SlaPolicyPolicy::class);

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
