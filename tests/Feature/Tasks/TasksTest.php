<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Models\Person;
use App\Core\Tasks\Actions\CloseTask;
use App\Core\Tasks\Actions\CreateTask;
use App\Core\Tasks\Enums\TaskStatus;
use App\Core\Tasks\Models\Task;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    $this->editor = memberWithRole($this->organisation, SystemRole::Editor);
    $this->assigneeUser = memberWithRole($this->organisation, SystemRole::Viewer);
    $this->assignee = Tenancy::run($this->organisation, fn () => Person::factory()->create(['user_id' => $this->assigneeUser->id]));
});

function createTask(User $actor, array $data, $about = null): Task
{
    return Tenancy::run(test()->organisation, fn () => app(CreateTask::class)->handle($actor, $data, $about));
}

it('creates a task about a record, assigned to someone in the organisation', function (): void {
    $task = createTask($this->editor, ['title' => 'Rotate the Stripe key', 'assignee_id' => $this->assignee->id, 'due_at' => '2026-12-01'], $this->assignee);

    expect($task)
        ->status->toBe(TaskStatus::Open)
        ->taskable_id->toBe($this->assignee->id)
        ->assignee_id->toBe($this->assignee->id);
});

it('lets only members who manage tasks create them', function (): void {
    createTask($this->assigneeUser, ['title' => 'Rotate the Stripe key']);
})->throws(AuthorizationException::class);

it('refuses an assignee or a record from another organisation', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $outsider = Tenancy::run($globex, fn () => Person::factory()->create());

    expect(fn (): Task => createTask($this->editor, ['title' => 'Rotate', 'assignee_id' => $outsider->id]))->toThrow(ValidationException::class)
        ->and(fn (): Task => createTask($this->editor, ['title' => 'Rotate'], $outsider))->toThrow(TenantMismatch::class);
});

it('lets the assignee close their own task, but not someone else\'s', function (): void {
    $theirs = createTask($this->editor, ['title' => 'Rotate the Stripe key', 'assignee_id' => $this->assignee->id]);
    $unassigned = createTask($this->editor, ['title' => 'Review access']);

    $closed = Tenancy::run($this->organisation, fn () => app(CloseTask::class)->handle($this->assigneeUser, $theirs));

    expect($closed)->status->toBe(TaskStatus::Done)->completed_by_id->toBe($this->assigneeUser->id)
        ->and(fn () => Tenancy::run($this->organisation, fn () => app(CloseTask::class)->handle($this->assigneeUser, $unassigned)))
        ->toThrow(AuthorizationException::class);
});

it('closes a task only once', function (): void {
    $task = createTask($this->editor, ['title' => 'Rotate the Stripe key']);
    Tenancy::run($this->organisation, fn () => app(CloseTask::class)->handle($this->editor, $task, TaskStatus::Cancelled));

    Tenancy::run($this->organisation, fn () => app(CloseTask::class)->handle($this->editor, $task));
})->throws(InvalidArgumentException::class);
