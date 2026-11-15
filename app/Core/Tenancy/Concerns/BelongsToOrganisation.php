<?php

namespace App\Core\Tenancy\Concerns;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use App\Core\Tenancy\Scopes\OrganisationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every model stored in a tenant table. Scopes queries to the current organisation and
 * stamps new rows with it; updates and deletes must stay inside it. `organisation_id` must never
 * be mass assignable.
 *
 * @mixin Model
 */
trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new OrganisationScope);

        static::creating(function (Model $model): void {
            if (Tenancy::isBypassing()) {
                return;
            }

            $current = Tenancy::id();
            $given = $model->getAttribute('organisation_id');

            if ($given !== null && $given !== $current) {
                throw TenantMismatch::for($model::class, (string) $given, $current);
            }

            $model->setAttribute('organisation_id', $current);
        });

        // A model loaded elsewhere (e.g. during a bypass) must still belong to the current
        // organisation to be changed, and can never be moved to another one.
        $guard = function (Model $model): void {
            if (Tenancy::isBypassing()) {
                return;
            }

            $current = Tenancy::id();
            $original = (string) $model->getOriginal('organisation_id');

            if ($original !== $current || $model->getAttribute('organisation_id') !== $current) {
                throw TenantMismatch::for($model::class, $original, $current);
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
