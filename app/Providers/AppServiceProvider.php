<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\OutgoingRequest;

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
        // Telegram puts the bot token in the request path: never send it to monitoring.
        Nightwatch::redactOutgoingRequests(function (OutgoingRequest $request): void {
            $request->url = (string) preg_replace('#/bot[^/]+/#', '/bot[redacted]/', $request->url);
        });
    }
}
