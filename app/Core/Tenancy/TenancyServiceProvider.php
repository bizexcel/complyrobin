<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Testing\ParallelDatabases;
use Closure;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
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
        // Scoped, not singletons: they hold one request's or job's organisation and user, and a long-running server
        // (a queue worker, Octane) forgets scoped services between requests and jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(JobTenancyStack::class);

        // The tenant connection carries the organisation in every statement (TenantConnection); the platform and
        // migrator connections bypass row-level security and stay plain Postgres connections.
        Connection::resolverFor('pgsql', function ($connection, string $database, string $prefix, array $config): PostgresConnection {
            if (($config['name'] ?? null) === TenantContext::CONNECTION) {
                return new TenantConnection($connection, $database, $prefix, $config);
            }

            return new PostgresConnection($connection, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        // Parallel test runs: every role connection moves to the test process's own database.
        if ($this->app->runningUnitTests()) {
            ParallelDatabases::register();
        }

        // Queued jobs run as the organisation they were dispatched in. Afterwards the organisation from before the job
        // comes back: none on a worker, or the surrounding one when a job runs synchronously inside Tenancy::run().
        Context::hydrated(function (Repository $context): void {
            $tenancy = app(TenantContext::class);
            app(JobTenancyStack::class)->push($tenancy->check() ? $tenancy->id() : null);

            if ($context->hasHidden(TenantContext::CONTEXT_KEY)) {
                $tenancy->set((string) $context->getHidden(TenantContext::CONTEXT_KEY));
            }
        });

        Event::listen([JobProcessed::class, JobExceptionOccurred::class], function (): void {
            $tenancy = app(TenantContext::class);
            $previous = app(JobTenancyStack::class)->pop();

            if ($previous !== null) {
                $tenancy->set($previous);
            } elseif ($tenancy->check()) {
                $tenancy->forget();
            }
        });

        // Mirror the signed-in user into the database, for policies that let users read their own rows.
        Event::listen(function (Authenticated $event): void {
            app(TenantContext::class)->setUser((string) $event->user->getAuthIdentifier());
        });

        Event::listen(function (Logout $event): void {
            app(TenantContext::class)->setUser(null);
        });

        $this->startCleanOnOctane();
        $this->registerSchemaMacros();
    }

    /**
     * Octane (not installed yet) runs each request, task and tick in a copy of the app. It forgets scoped services
     * when one ends normally; this also drops them when one begins, so nothing survives a request that ended badly.
     * The events are named as strings so the package isn't needed.
     */
    private function startCleanOnOctane(): void
    {
        Event::listen([
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\TaskReceived',
            'Laravel\Octane\Events\TickReceived',
        ], function (object $event): void {
            foreach ([$event->app, $event->sandbox] as $container) {
                $container->forgetInstance(TenantContext::class);
                $container->forgetInstance(JobTenancyStack::class);
            }
        });
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
