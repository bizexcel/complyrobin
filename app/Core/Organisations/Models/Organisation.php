<?php

namespace App\Core\Organisations\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant. Sub-organisations point at their parent; billing resolves through root_id.
 * Not row-level-security scoped itself: access is decided by memberships.
 *
 * @property string $slug
 * @property string $timezone
 * @property list<string> $previous_slugs slugs it used before; they redirect here and nobody else may take them
 */
#[Fillable(['name', 'slug', 'timezone', 'parent_id', 'root_id', 'settings'])]
#[UseFactory(OrganisationFactory::class)]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUuids, LogsTenantActivity, SoftDeletes, UsesTenancyConnection;

    /**
     * Only changes are audited: an organisation is created before it has an audit trail to write to.
     *
     * @var list<string>
     */
    protected static array $recordEvents = ['updated'];

    /**
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'timezone' => 'Asia/Kuala_Lumpur',
        'previous_slugs' => '[]',
    ];

    /**
     * Row-level security lets the app change only the current organisation and delete none, and a blocked change
     * silently affects no rows. Refuse such changes loudly instead: deleting an organisation is platform work.
     */
    protected static function booted(): void
    {
        static::updating(function (Organisation $organisation): void {
            if (! Tenancy::isBypassing() && (! Tenancy::check() || $organisation->getKey() !== Tenancy::id())) {
                throw TenantMismatch::for(self::class, $organisation->getKey(), Tenancy::check() ? Tenancy::id() : 'none');
            }
        });

        static::deleting(function (Organisation $organisation): void {
            if (! Tenancy::isBypassing()) {
                throw TenantMismatch::for(self::class, $organisation->getKey(), 'none (deleting needs Tenancy::bypass())');
            }
        });
    }

    /**
     * @return BelongsToMany<User, $this, Membership>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(Membership::class)->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'previous_slugs' => 'array',
        ];
    }
}
