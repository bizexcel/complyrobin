<?php

namespace App\Core\Audit\Models;

use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * An audit entry in the current organisation's trail.
 */
class Activity extends SpatieActivity
{
    use BelongsToOrganisation, HasUuids;

    #[\Override]
    protected $table = 'activities';
}
