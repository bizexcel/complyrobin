<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's private calendar link of upcoming rotations. The token is a sha-256 hash of the secret part of the link,
 * hidden so it is never serialised or written to the audit trail.
 *
 * @property string $user_id
 * @property string $token
 * @property Carbon|null $last_used_at
 */
#[Fillable(['user_id', 'token'])]
#[Hidden(['token'])]
class CalendarFeed extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }
}
