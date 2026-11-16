<?php

namespace App\Core\Identity\Models;

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to someone's ComplyRobin staff role. Written only by ChangeStaffRole, on the platform connection, and
 * never changed or deleted. The database stamps the time, so changes made within one second still sort correctly.
 *
 * @property string|null $user_id
 * @property string|null $changed_by_id null when made from the server console
 * @property PlatformRole|null $from_role null: wasn't staff
 * @property PlatformRole|null $to_role null: removed from staff
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'changed_by_id', 'from_role', 'to_role'])]
class StaffRoleChange extends Model
{
    use HasUuids, UsesTenancyConnection;

    #[\Override]
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_role' => PlatformRole::class,
            'to_role' => PlatformRole::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
