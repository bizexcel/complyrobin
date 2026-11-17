<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Modules\Credentials\Enums\RotationSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * How a runner rotates one credential: where it makes the new key and where it puts it. Ids only, never a key.
 *
 * @property string $credential_id
 * @property string $runner_id
 * @property RotationSource $source
 * @property array{project_id: string, service_account_id: string, key_lifetime_days?: int|null} $source_settings
 * @property list<array{type: string, secret_id: string, secret_name: string, environment_ids?: list<string>, redeploy: bool}> $destinations
 * @property Carbon|null $requested_at
 */
#[Fillable(['credential_id', 'runner_id', 'source', 'source_settings', 'destinations'])]
class AutomaticRotation extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /**
     * @return BelongsTo<Runner, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(Runner::class);
    }

    /**
     * Every run of the credential, whichever runner made it.
     *
     * @return HasMany<RotationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RotationRun::class, 'credential_id', 'credential_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => RotationSource::class,
            'source_settings' => 'array',
            'destinations' => 'array',
            'requested_at' => 'datetime',
        ];
    }
}
