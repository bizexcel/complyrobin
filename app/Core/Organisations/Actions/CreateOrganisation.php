<?php

namespace App\Core\Organisations\Actions;

use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\OrganisationSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateOrganisation
{
    public function __construct(private OrganisationSlugs $slugs) {}

    /**
     * Create a top-level organisation, named in URLs by a slug generated from its name.
     * If another sign-up takes the same slug at the same moment, a fresh slug is generated and the insert retried.
     */
    public function handle(string $name): Organisation
    {
        return DB::transaction(fn (): Organisation => retry(
            3,
            fn (): Organisation => DB::transaction(fn (): Organisation => Organisation::create([
                'name' => $name,
                'slug' => $this->slugs->generate($name),
            ])),
            when: fn (Throwable $exception): bool => $exception instanceof UniqueConstraintViolationException,
        ));
    }
}
