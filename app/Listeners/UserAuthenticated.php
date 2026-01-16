<?php

namespace App\Listeners;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UserAuthenticated
{
    /**
     * Create the event listener.
     */
    public function __construct(Authenticatable $user)
    {

    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        //
    }
}
