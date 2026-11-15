<?php

namespace App\Core\Tasks\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\Tasks\Models\Task;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CreateTask
{
    /**
     * Create a task in the current organisation, optionally about one of its records.
     *
     * @param  array<string, mixed>  $data  title, notes, assignee_id (a person), due_at
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, ?Model $about = null): Task
    {
        Gate::forUser($actor)->authorize(Ability::ManageTasks->value);

        if ($about instanceof Model && $about->getAttribute('organisation_id') !== Tenancy::id()) {
            throw TenantMismatch::for($about::class, (string) $about->getAttribute('organisation_id'), Tenancy::id());
        }

        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'assignee_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organisation_id', Tenancy::id())->whereNull('deleted_at')],
            'due_at' => ['nullable', 'date'],
        ])->validate();

        return DB::transaction(function () use ($validated, $about): Task {
            $task = new Task($validated);
            $task->taskable()->associate($about);
            $task->save();

            return $task;
        });
    }
}
