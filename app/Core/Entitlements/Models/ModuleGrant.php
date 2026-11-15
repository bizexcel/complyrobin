<?php

namespace App\Core\Entitlements\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A module granted to a root organisation by platform staff, for a period.
 *
 * @property Module $module
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable(['module', 'granted_by_id', 'starts_at', 'ends_at', 'note'])]
class ModuleGrant extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module' => Module::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
