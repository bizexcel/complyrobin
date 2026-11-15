<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Modules\Credentials\Support\RotationSchedule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One recorded rotation. Never changed or deleted by the app.
 *
 * @property string $credential_id
 * @property string|null $rotated_by_id
 * @property Carbon $rotated_at
 * @property Carbon $due_at
 * @property string|null $notes
 * @property Carbon|null $created_at when it was recorded
 */
#[Fillable(['credential_id', 'rotated_by_id', 'rotated_at', 'due_at', 'notes'])]
class CredentialRotation extends Model implements HasMedia
{
    use BelongsToOrganisation, HasUuids, InteractsWithMedia, LogsTenantActivity;

    public const null UPDATED_AT = null;

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->acceptsMimeTypes(['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'text/plain']);
    }

    /**
     * Rotated no later than the organisation's due day.
     */
    public function isOnTime(string $timezone): bool
    {
        return RotationSchedule::daysUntilDue($this->due_at, $this->rotated_at, $timezone) >= 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rotated_at' => 'datetime',
            'due_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
