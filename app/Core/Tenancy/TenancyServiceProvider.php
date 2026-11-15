<?php

namespace App\Core\Tenancy;

use Closure;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(JobTenancyStack::class);
    }

    public function boot(): void
    {
        // A new or re-established tenant connection starts with an empty session: re-apply the tenant.
        Event::listen(function (ConnectionEstablished $event): void {
            $context = $this->app->make(TenantContext::class);

            if ($event->connectionName === TenantContext::CONNECTION && ($context->check() || $context->userId() !== null)) {
                $context->applyTo($event->connection);
            }
        });

        // set_config() is transactional: a rollback (or rollback to savepoint) undoes it. Re-apply the
        // tenant PHP holds so the database and PHP never disagree.
        Event::listen(function (TransactionRolledBack $event): void {
            if ($event->connectionName === TenantContext::CONNECTION) {
                $this->app->make(TenantContext::class)->applyTo($event->connection);
            }
        });

        // Queued jobs run as the organisation they were dispatched in. Afterwards the organisation from before the job
        // comes back: none on a worker, or the surrounding one when a job runs synchronously inside Tenancy::run().
        Context::hydrated(function (Repository $context): void {
            $tenancy = $this->app->make(TenantContext::class);
            $this->app->make(JobTenancyStack::class)->push($tenancy->check() ? $tenancy->id() : null);

            if ($context->hasHidden(TenantContext::CONTEXT_KEY)) {
                $tenancy->set((string) $context->getHidden(TenantContext::CONTEXT_KEY));
            }
        });

        Event::listen([JobProcessed::class, JobExceptionOccurred::class], function (): void {
            $tenancy = $this->app->make(TenantContext::class);
            $previous = $this->app->make(JobTenancyStack::class)->pop();

            if ($previous !== null) {
                $tenancy->set($previous);
            } elseif ($tenancy->check()) {
                $tenancy->forget();
            }
        });

        // Mirror the signed-in user into the database, for policies that let users read their own rows.
        Event::listen(function (Authenticated $event): void {
            $this->app->make(TenantContext::class)->setUser((string) $event->user->getAuthIdentifier());
        });

        Event::listen(function (Logout $event): void {
            $this->app->make(TenantContext::class)->setUser(null);
        });

        $this->registerSchemaMacros();
    }

    private function registerSchemaMacros(): void
    {
        /*
         * Create a tenant table: uuid id, organisation_id, unique (organisation_id, id) for composite
         * foreign keys, and a forced row-level security policy keyed on app.organisation_id.
         */
        Builder::macro('tenantTable', function (string $table, Closure $callback): void {
            /** @var Builder $this */
            $this->create($table, function (Blueprint $blueprint) use ($callback): void {
                $blueprint->uuid('id')->primary();
                $blueprint->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
                $blueprint->unique(['organisation_id', 'id']);

                $callback($blueprint);
            });

            $this->tenantIsolation($table);
        });

        /*
         * Force row-level security on an existing table with an organisation_id column, e.g. a pivot table that
         * has no id of its own. Rows are visible and writable only while app.organisation_id matches.
         */
        Builder::macro('tenantIsolation', function (string $table): void {
            /** @var Builder $this */
            $condition = "organisation_id = nullif(current_setting('app.organisation_id', true), '')::uuid";
            $connection = $this->getConnection();

            $connection->statement("alter table \"{$table}\" enable row level security");
            $connection->statement("alter table \"{$table}\" force row level security");
            $connection->statement("create policy \"{$table}_tenant_isolation\" on \"{$table}\" using ({$condition}) with check ({$condition})");
        });

        /*
         * Composite foreign key to another tenant table, so the database rejects cross-tenant links.
         */
        Blueprint::macro('tenantForeign', function (string $column, string $on, string $onDelete = 'restrict'): ForeignKeyDefinition {
            /** @var Blueprint $this */
            return $this->foreign(['organisation_id', $column])
                ->references(['organisation_id', 'id'])
                ->on($on)
                ->onDelete($onDelete);
        });
    }
}
