<?php

namespace App\Core\Tenancy;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Events\TenantChanged;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\Exceptions\TenancyBypassDenied;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The organisation the current request or job works for.
 *
 * The tenant connection (TenantConnection) sends the id with every statement as the transaction-local setting
 * `app.organisation_id`, which the row-level security policies on every tenant table compare against, and the
 * signed-in user as `app.user_id`, so a user can read their own memberships before choosing one. Nothing is kept in
 * the database session, so this holds through a transaction-mode pooler.
 */
class TenantContext
{
    /** The connection that runs as the RLS-restricted tenant role. */
    public const string CONNECTION = 'pgsql';

    /** The connection that runs as the RLS-bypassing platform role. */
    public const string PLATFORM_CONNECTION = 'pgsql_platform';

    /** Where the organisation travels in Laravel's (hidden) Context, and so into every queued job. */
    public const string CONTEXT_KEY = 'tenancy.organisation_id';

    /** A UUID, or empty for none. */
    private const string SAFE_ID = '/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})?$/';

    private ?string $organisationId = null;

    private ?string $userId = null;

    private bool $bypassing = false;

    private ?Closure $bypassAllowed = null;

    public function __construct(private readonly DatabaseManager $db, private readonly Dispatcher $events) {}

    /**
     * Set the current organisation.
     *
     * @throws InvalidArgumentException when the id is not a UUID
     */
    public function set(Organisation|string $organisation): void
    {
        $id = $organisation instanceof Organisation ? $organisation->getKey() : $organisation;

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new InvalidArgumentException('The organisation id must be a UUID.');
        }

        $this->organisationId = $id;
        Context::addHidden(self::CONTEXT_KEY, $id);

        $this->events->dispatch(new TenantChanged($id));
    }

    /**
     * Get the current organisation id.
     *
     * @throws MissingTenantContext
     */
    public function id(): string
    {
        return $this->organisationId ?? throw MissingTenantContext::make();
    }

    /**
     * Determine if an organisation is set.
     */
    public function check(): bool
    {
        return $this->organisationId !== null;
    }

    /**
     * Clear the current organisation.
     */
    public function forget(): void
    {
        $this->organisationId = null;
        Context::forgetHidden(self::CONTEXT_KEY);

        $this->events->dispatch(new TenantChanged(null));
    }

    /**
     * Set (or clear) the signed-in user. Called from the authentication events, not by application code.
     *
     * @throws InvalidArgumentException when the id is not a UUID
     */
    public function setUser(?string $userId): void
    {
        if ($userId !== null && ! Str::isUuid($userId)) {
            throw new InvalidArgumentException('The user id must be a UUID.');
        }

        $this->userId = $userId;
    }

    /**
     * Get the signed-in user's id, if any.
     */
    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Run the callback as the given organisation, restoring the previous one afterwards.
     *
     * Inside a bypass, the callback leaves the bypass: it runs scoped, on the tenant connection.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Organisation|string $organisation, Closure $callback): mixed
    {
        $previous = $this->organisationId;
        $previousBypassing = $this->bypassing;
        $previousConnection = $this->db->getDefaultConnection();

        $this->set($organisation);
        $this->bypassing = false;
        $this->db->setDefaultConnection(self::CONNECTION);

        try {
            return $callback();
        } finally {
            $this->bypassing = $previousBypassing;
            $this->db->setDefaultConnection($previousConnection);
            $previous === null ? $this->forget() : $this->set($previous);
        }
    }

    /**
     * Run the callback inside each organisation in turn, e.g. for scheduled work. Deleted organisations are skipped.
     *
     * @param  Closure(Organisation): void  $callback
     */
    public function each(Closure $callback): void
    {
        foreach (Organisation::query()->lazyById(500) as $organisation) {
            $this->run($organisation, fn () => $callback($organisation));
        }
    }

    /**
     * Run the callback across every organisation, on the RLS-bypassing platform connection.
     *
     * Not allowed inside an open tenant transaction: the platform connection is a separate
     * session that can't see the transaction's rows and could deadlock against its locks.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws TenancyBypassDenied
     */
    public function bypass(string $reason, Closure $callback): mixed
    {
        if (! $this->bypassIsAllowed()) {
            throw TenancyBypassDenied::because($reason);
        }

        if ($this->db->connection(self::CONNECTION)->transactionLevel() > 0) {
            throw TenancyBypassDenied::insideTransaction($reason);
        }

        Log::info('tenancy.bypass', ['reason' => $reason]);

        $previousConnection = $this->db->getDefaultConnection();
        $previousBypassing = $this->bypassing;

        $this->bypassing = true;
        $this->db->setDefaultConnection(self::PLATFORM_CONNECTION);

        try {
            return $callback();
        } finally {
            $this->bypassing = $previousBypassing;
            $this->db->setDefaultConnection($previousConnection);
        }
    }

    /**
     * Set the organisation for the rest of the current request, and clear it when the request ends. Only for the
     * customer panel's tenant middleware.
     *
     * Not Tenancy::run(): after a page loads, every click is a Livewire update request, and Livewire re-runs a panel's
     * middleware around an empty response and handles the component afterwards. Anything a middleware scopes to its
     * $next call is over before the component runs.
     */
    public function setUntilTheRequestEnds(Organisation $organisation): void
    {
        $this->set($organisation);

        app()->terminating(function (): void {
            $this->forget();
        });
    }

    /**
     * Bypass for the rest of the current request, and stop when it ends. Only for the staff panel's middleware
     * (RunAsPlatform), for the same reason as setUntilTheRequestEnds().
     *
     * @throws TenancyBypassDenied
     */
    public function bypassUntilTheRequestEnds(string $reason): void
    {
        if (! $this->bypassIsAllowed()) {
            throw TenancyBypassDenied::because($reason);
        }

        Log::info('tenancy.bypass', ['reason' => $reason]);

        $previousConnection = $this->db->getDefaultConnection();
        $this->bypassing = true;
        $this->db->setDefaultConnection(self::PLATFORM_CONNECTION);

        app()->terminating(function () use ($previousConnection): void {
            $this->bypassing = false;
            $this->db->setDefaultConnection($previousConnection);
        });
    }

    /**
     * Determine if tenant scoping is currently bypassed.
     */
    public function isBypassing(): bool
    {
        return $this->bypassing;
    }

    /**
     * Decide when bypass is allowed. Defaults to console commands only.
     *
     * @param  (Closure(): bool)|null  $callback
     */
    public function allowBypassUsing(?Closure $callback): void
    {
        $this->bypassAllowed = $callback;
    }

    /**
     * What the tenant connection sends before every statement: the current organisation and user, set for that
     * statement only. Always sent, even when empty, so nothing another client left on a pooled session can apply.
     */
    public function statementPrefix(): string
    {
        $organisationId = $this->organisationId ?? '';
        $userId = $this->userId ?? '';

        // Both are checked as UUIDs when set; checked again because they are written into the SQL.
        if (preg_match(self::SAFE_ID, $organisationId) !== 1 || preg_match(self::SAFE_ID, $userId) !== 1) {
            throw new LogicException('The tenant ids must be UUIDs.');
        }

        return "select set_config('app.organisation_id', '{$organisationId}', true), set_config('app.user_id', '{$userId}', true);\n";
    }

    private function bypassIsAllowed(): bool
    {
        return $this->bypassAllowed instanceof Closure
            ? (bool) ($this->bypassAllowed)()
            : app()->runningInConsole();
    }
}
