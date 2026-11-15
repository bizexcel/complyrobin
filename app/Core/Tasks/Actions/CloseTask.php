<?php

namespace App\Core\Tasks\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\Tasks\Enums\TaskStatus;
use App\Core\Tasks\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class CloseTask
{
    /**
     * Mark an open task done or cancelled. Its assignee may close it; so may anyone who manages tasks.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Task $task, TaskStatus $status = TaskStatus::Done): Task
    {
        if ($status === TaskStatus::Open) {
            throw new InvalidArgumentException('Close a task as done or cancelled.');
        }

        $isAssignee = $task->assignee !== null && $task->assignee->user_id === $actor->getKey();

        if (! $isAssignee && ! $actor->can(Ability::ManageTasks->value)) {
            throw new AuthorizationException('Only the assignee or someone who manages tasks can close this task.');
        }

        if ($task->status !== TaskStatus::Open) {
            throw new InvalidArgumentException('This task is already closed.');
        }

        $task->forceFill([
            'status' => $status,
            'completed_at' => now(),
            'completed_by_id' => $actor->getKey(),
        ])->save();

        return $task;
    }
}
