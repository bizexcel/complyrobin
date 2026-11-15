<?php

namespace App\Core\Organisations\Actions;

use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Rules\OrganisationSlug;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RenameOrganisationSlug
{
    /**
     * Change the current organisation's slug. The old slug keeps redirecting and stays reserved for this organisation.
     *
     * Row-level security only lets the tenant role update the current organisation, so renaming any other one would
     * silently change nothing: refuse it instead.
     *
     * @throws ValidationException
     */
    public function handle(Organisation $organisation, string $slug): Organisation
    {
        if ($organisation->getKey() !== Tenancy::id()) {
            throw TenantMismatch::for(Organisation::class, $organisation->getKey(), Tenancy::id());
        }

        Validator::make(['slug' => $slug], ['slug' => ['required', new OrganisationSlug($organisation)]])->validate();

        if ($slug === $organisation->slug) {
            return $organisation;
        }

        return DB::transaction(function () use ($organisation, $slug): Organisation {
            $organisation->previous_slugs = array_values(array_unique([
                ...array_diff($organisation->previous_slugs, [$slug]),
                $organisation->slug,
            ]));
            $organisation->slug = $slug;
            $organisation->save();

            return $organisation;
        });
    }
}
