<?php

namespace App\Core\Audit\Models;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * An audit entry in an organisation's trail. Inside an organisation it belongs to the current one; platform staff
 * working across organisations (Tenancy::bypass()) leave it on the trail of the organisation the change concerns.
 */
class Activity extends SpatieActivity
{
    use BelongsToOrganisation, HasUuids;

    #[\Override]
    protected $table = 'activities';

    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            if ($activity->getAttribute('organisation_id') !== null) {
                return;
            }

            $subject = $activity->subject;

            $activity->setAttribute('organisation_id', match (true) {
                $subject instanceof Organisation => $subject->getKey(),
                $subject instanceof Model => $subject->getAttribute('organisation_id'),
                default => null,
            });
        });
    }
}
