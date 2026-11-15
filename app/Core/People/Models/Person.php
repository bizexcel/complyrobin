<?php

namespace App\Core\People\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone in the organisation: staff or a contact, with or without a login.
 *
 * @property string $name
 * @property string|null $email
 */
#[Fillable(['user_id', 'department_id', 'name', 'email', 'phone', 'job_title'])]
#[UseFactory(PersonFactory::class)]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids, LogsTenantActivity, SoftDeletes;

    #[\Override]
    protected $table = 'people';

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
