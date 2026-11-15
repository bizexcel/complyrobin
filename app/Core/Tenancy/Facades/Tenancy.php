<?php

namespace App\Core\Tenancy\Facades;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(\App\Core\Organisations\Models\Organisation|string $organisation)
 * @method static string id()
 * @method static bool check()
 * @method static void forget()
 * @method static void setUser(string|null $userId)
 * @method static string|null userId()
 * @method static mixed run(\App\Core\Organisations\Models\Organisation|string $organisation, \Closure $callback)
 * @method static mixed bypass(string $reason, \Closure $callback)
 * @method static void each(\Closure $callback)
 * @method static bool isBypassing()
 * @method static void allowBypassUsing(\Closure|null $callback)
 *
 * @see TenantContext
 */
class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }
}
