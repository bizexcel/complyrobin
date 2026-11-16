<?php

namespace App\Core\Tenancy;

use Closure;
use Illuminate\Database\PostgresConnection;

/**
 * The application's own connection (app_tenant). Every statement it sends starts by setting the current organisation
 * and user for that statement only (transaction-local set_config), so row-level security always sees the tenant PHP
 * holds and nothing is left in the database session. That keeps tenants apart through a transaction-mode pooler
 * (Laravel Cloud's), where consecutive statements can run on different server sessions shared with other clients.
 *
 * The prefix makes each query two statements in one message, which only emulated prepares allow (config/database.php).
 */
final class TenantConnection extends PostgresConnection
{
    private ?TenantContext $tenancy = null;

    public function useTenancy(TenantContext $tenancy): self
    {
        $this->tenancy = $tenancy;

        return $this;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    #[\Override]
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        $prefix = ($this->tenancy ?? app(TenantContext::class))->statementPrefix();

        return parent::runQueryCallback($query, $bindings, fn (string $query, array $bindings): mixed => $callback($prefix.$query, $bindings));
    }
}
