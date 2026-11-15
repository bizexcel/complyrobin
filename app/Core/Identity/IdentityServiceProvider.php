<?php

namespace App\Core\Identity;

use App\Core\Identity\Listeners\RecordLoginAttempt;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([Login::class, Failed::class], RecordLoginAttempt::class);
    }
}
