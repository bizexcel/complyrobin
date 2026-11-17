<?php

namespace App\Billing;

use App\Billing\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel Cashier's CashierServiceProvider, for Airwallex.
 */
class BillingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
    }

    /**
     * Register the routes.
     */
    protected function registerRoutes(): void
    {
        if (Cashier::$registersRoutes) {
            Route::group([
                'prefix' => config('billing.path'),
                'as' => 'cashier.',
            ], function (): void {
                Route::post('webhook', [WebhookController::class, 'handleWebhook'])->name('webhook');
            });
        }
    }
}
