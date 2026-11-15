<?php

namespace App\Core\Tasks\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Identity\Models\User;
use App\Core\People\Models\Person;
use App\Core\Tasks\Enums\TaskStatus;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property TaskStatus $status
 * @property string|null $assignee_id
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 */
#[Fillable(['title', 'notes', 'assignee_id', 'due_at'])]
class Task extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    /**
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = ['status' => 'open'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
