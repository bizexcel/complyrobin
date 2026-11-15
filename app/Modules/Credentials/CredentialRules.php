<?php

namespace App\Modules\Credentials;

use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\CredentialType;
use App\Modules\Credentials\Enums\Environment;
use App\Modules\Credentials\Rules\NotASecret;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validation for a credential's details. Never the secret: free-text fields refuse anything that looks like one.
 */
final class CredentialRules
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function validate(array $data): array
    {
        $organisationId = Tenancy::id();
        $person = fn () => Rule::exists('people', 'id')->where('organisation_id', $organisationId)->whereNull('deleted_at');

        return Validator::make($data, [
            'application_id' => ['required', 'uuid', Rule::exists('applications', 'id')->where('organisation_id', $organisationId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255', new NotASecret],
            'type' => ['required', Rule::enum(CredentialType::class)],
            'environment' => ['required', Rule::enum(Environment::class)],
            'owner_id' => ['nullable', 'uuid', $person()],
            'backup_owner_id' => ['nullable', 'uuid', 'different:owner_id', $person()],
            'used_in' => ['nullable', 'string', 'max:2000', new NotASecret],
            'hint' => ['nullable', 'string', 'max:'.(int) config('credentials.hint_max_length'), new NotASecret],
            'rotation_days' => ['nullable', 'integer', 'between:1,1095', 'required_without:expires_at'],
            'last_rotated_at' => ['nullable', 'date', 'before_or_equal:now'],
            'expires_at' => ['nullable', 'date', 'required_without:rotation_days'],
        ], [
            'backup_owner_id.different' => 'The backup owner must be someone other than the owner.',
            'rotation_days.required_without' => 'Set how often it is rotated, or when it expires.',
            'hint.max' => 'A hint is the first or last few characters only (at most :max).',
        ])->validate();
    }
}
