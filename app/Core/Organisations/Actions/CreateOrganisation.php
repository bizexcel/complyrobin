<?php

namespace App\Core\Organisations\Actions;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\OrganisationSlugs;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateOrganisation
{
    public function __construct(private OrganisationSlugs $slugs) {}

    /**
     * Create a top-level organisation, named in URLs by a slug generated from its name, with its creator as the first
     * member. If another sign-up takes the same slug at the same moment, a fresh slug is generated and the insert retried.
     */
    public function handle(User $owner, string $name): Organisation
    {
        return DB::transaction(function () use ($owner, $name): Organisation {
            $organisation = retry(
                3,
                fn (): Organisation => DB::transaction(fn (): Organisation => Organisation::create([
                    'name' => $name,
                    'slug' => $this->slugs->generate($name),
                ])),
                when: fn (Throwable $exception): bool => $exception instanceof UniqueConstraintViolationException,
            );

            Tenancy::run($organisation, fn () => $organisation->users()->attach($owner));

            return $organisation;
        });
    }
}
