<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Modules\Credentials\Enums\RotationRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time a runner claimed a credential to rotate, and how it went. Holds the vendor's ids for what changed, never a
 * key.
 *
 * @property string $credential_id
 * @property string $runner_id
 * @property RotationRunStatus $status
 * @property Carbon $claimed_at
 * @property Carbon|null $finished_at
 * @property string|null $credential_rotation_id
 * @property string|null $new_key_identifier
 * @property string|null $new_key_hint
 * @property Carbon|null $old_keys_expire_at
 * @property list<string>|null $deployments
 * @property string|null $failed_step
 * @property string|null $failure
 */
#[Fillable(['credential_id', 'runner_id', 'status', 'claimed_at'])]
class RotationRun extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    public const null CREATED_AT = null;

    public const null UPDATED_AT = null;

    /**
     * Runs are themselves the log of what runners did, so they aren't written to the audit trail as well.
     *
     * @var list<string>
     */
    protected static array $recordEvents = [];

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Runner, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class);
    }

    /**
     * @return BelongsTo<CredentialRotation, $this>
     */
    public function rotation(): BelongsTo
    {
        return $this->belongsTo(CredentialRotation::class, 'credential_rotation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RotationRunStatus::class,
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'old_keys_expire_at' => 'datetime',
            'deployments' => 'array',
        ];
    }
}
