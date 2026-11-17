<?php

namespace App\Core\Organisations\Actions;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Support\Countries;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateOrganisationProfile
{
    /**
     * Change the current organisation's name and time zone. The time zone decides the organisation's days: when
     * reminders go out and when something counts as overdue.
     *
     * @param  array{name?: string, timezone?: string}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Organisation $organisation, array $data): Organisation
    {
        if ($organisation->getKey() !== Tenancy::id()) {
            throw TenantMismatch::for(Organisation::class, $organisation->getKey(), Tenancy::id());
        }

        Gate::forUser($actor)->authorize('update', $organisation);

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'country' => ['sometimes', 'required', Rule::in(Countries::codes())],
        ])->validate();

        $organisation->update($validated);

        return $organisation;
    }
}
