<?php

namespace App\Core\Identity\Models;

use App\Core\Identity\Enums\LoginAttemptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sign-in attempt. Written by the app, readable only by platform staff (the tenant role can't select it).
 *
 * @property LoginAttemptStatus $status
 */
#[Fillable(['user_id', 'email', 'ip_address', 'user_agent', 'status'])]
class LoginAttempt extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoginAttemptStatus::class,
            'created_at' => 'datetime',
        ];
    }
}
