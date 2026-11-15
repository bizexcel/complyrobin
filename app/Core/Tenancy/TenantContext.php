<?php

namespace App\Core\Tenancy;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\Exceptions\TenancyBypassDenied;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The organisation the current request or job works for.
 *
 * The id is mirrored into the Postgres session setting `app.organisation_id`, which the
 * row-level security policies on every tenant table compare against. The signed-in user is
 * mirrored into `app.user_id`, so a user can read their own memberships before choosing one.
 */
class TenantContext
{
    /** The connection that runs as the RLS-restricted tenant role. */
    public const string CONNECTION = 'pgsql';

    /** The connection that runs as the RLS-bypassing platform role. */
    public const string PLATFORM_CONNECTION = 'pgsql_platform';

    private ?string $organisationId = null;

    private ?string $userId = null;

    private bool $bypassing = false;

    private ?Closure $bypassAllowed = null;

    public function __construct(private readonly DatabaseManager $db) {}

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

        // Database first: if the statement fails, PHP keeps the previous tenant too.
        $this->write($id, $this->userId);
        $this->organisationId = $id;
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
        $this->write(null, $this->userId);
        $this->organisationId = null;
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

        $this->write($this->organisationId, $userId);
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
     * Write the current organisation and user into a connection's session, e.g. after it reconnects.
     */
    public function applyTo(Connection $connection): void
    {
        $this->writeTo($connection, $this->organisationId, $this->userId);
    }

    private function write(?string $organisationId, ?string $userId): void
    {
        $this->writeTo($this->db->connection(self::CONNECTION), $organisationId, $userId);
    }

    private function writeTo(Connection $connection, ?string $organisationId, ?string $userId): void
    {
        $connection->statement(
            "select set_config('app.organisation_id', ?, false), set_config('app.user_id', ?, false)",
            [$organisationId ?? '', $userId ?? ''],
        );
    }

    private function bypassIsAllowed(): bool
    {
        return $this->bypassAllowed instanceof Closure
            ? (bool) ($this->bypassAllowed)()
            : app()->runningInConsole();
    }
}
