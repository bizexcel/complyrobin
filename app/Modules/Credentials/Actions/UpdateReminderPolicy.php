<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Support\ReminderPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateReminderPolicy
{
    /**
     * Change when the current organisation is reminded: up to six steps before the due date (1–90 days), up to six
     * while overdue (0–90 days, 0 being the due day), and the hour reminders start going out.
     *
     * @param  array{before?: array<mixed>, overdue?: array<mixed>, send_hour?: mixed}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, array $data): ReminderPolicy
    {
        if ($organisation->getKey() !== Tenancy::id()) {
            throw TenantMismatch::for(Organisation::class, $organisation->getKey(), Tenancy::id());
        }

        Gate::forUser($actor)->authorize('update', $organisation);

        $validated = Validator::make($data, [
            'before' => ['required', 'array', 'min:1', 'max:6'],
            'before.*' => ['integer', 'between:1,90', 'distinct'],
            'overdue' => ['present', 'array', 'max:6'],
            'overdue.*' => ['integer', 'between:0,90', 'distinct'],
            'send_hour' => ['required', 'integer', 'between:0,23'],
        ])->validate();

        $organisation->update(['settings' => [
            ...($organisation->settings ?? []),
            'reminders' => [
                'before' => array_map(intval(...), $validated['before']),
                'overdue' => array_map(intval(...), $validated['overdue']),
                'send_hour' => (int) $validated['send_hour'],
            ],
        ]]);

        return ReminderPolicy::for($organisation);
    }
}
