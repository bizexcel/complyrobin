<?php

namespace App\Core\Organisations\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership of an organisation (the `organisation_user` pivot). Roles within the organisation come from
 * spatie permission, scoped to the organisation as the team.
 */
class Membership extends Pivot
{
    use BelongsToOrganisation, HasUuids;

    #[\Override]
    protected $table = 'organisation_user';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
