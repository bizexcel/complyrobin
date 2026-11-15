<?php

namespace App\Core\Organisations\Rules;

use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\OrganisationSlugs;
use App\Core\Organisations\ReservedSlugs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A well-formed, unreserved slug that no other organisation uses or used before.
 * Pass the organisation being renamed so it may keep or take back its own slugs.
 */
final readonly class OrganisationSlug implements ValidationRule
{
    public function __construct(
        private ?Organisation $organisation = null,
        private OrganisationSlugs $slugs = new OrganisationSlugs,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || strlen($value) < OrganisationSlugs::MIN_LENGTH
            || strlen($value) > OrganisationSlugs::MAX_LENGTH
            || ! preg_match(OrganisationSlugs::PATTERN, $value)) {
            $fail('The :attribute must be 3 to 40 lowercase letters, numbers and single hyphens, starting and ending with a letter or number.');

            return;
        }

        if (ReservedSlugs::contains($value)) {
            $fail('The :attribute is reserved. Please choose another.');

            return;
        }

        if (! $this->slugs->isAvailable($value, $this->organisation)) {
            $fail('The :attribute is already taken.');
        }
    }
}
