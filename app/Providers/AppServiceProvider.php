<?php

namespace App\Providers;

use App\Core\Files\PrivateDisk;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Postgres only: Laravel merges its own sqlite/mysql/mariadb/sqlsrv defaults into config/database.php; drop them.
        // Postgres connections Laravel Cloud adds (e.g. pgsql-unpooled) stay.
        config(['database.connections' => array_filter(
            (array) config('database.connections'),
            fn (mixed $connection): bool => is_array($connection) && ($connection['driver'] ?? null) === 'pgsql',
        )]);

        // Laravel Cloud attaches its buckets after the config loads, so the private disk is settled here.
        config(['media-library.disk_name' => PrivateDisk::name()]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Calendar apps poll every few minutes to hours. Many users share a provider's addresses, so the per-address
        // limit is generous; each link also has its own.
        RateLimiter::for('calendar-feed', fn (Request $request): array => [
            Limit::perMinute(300)->by('calendar-ip:'.$request->ip()),
            Limit::perHour(60)->by('calendar-link:'.hash('sha256', (string) $request->route('token'))),
        ]);

        // Telegram puts the bot token in the request path: never send it to monitoring.
        Nightwatch::redactOutgoingRequests(function (OutgoingRequest $request): void {
            $request->url = (string) preg_replace('#/bot[^/]+/#', '/bot[redacted]/', $request->url);
        });
    }
}
