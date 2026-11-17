<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rotation runner: a program the organisation runs in its own GitHub account or Laravel app, which swaps keys and
 * reports back. The token is a sha-256 hash of the runner's secret token, hidden so it is never serialised or written
 * to the audit trail.
 *
 * @property string $name
 * @property string $token
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['name', 'token'])]
#[Hidden(['token'])]
class Runner extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
