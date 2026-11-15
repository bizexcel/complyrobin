<?php

namespace App\Core\People;

use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Validation\Rule;

/**
 * Validation for a person's details. Ids are only accepted from the current organisation.
 */
final class PersonRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $organisationId = Tenancy::id();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'phone:INTERNATIONAL,MY'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'uuid', Rule::exists('departments', 'id')->where('organisation_id', $organisationId)->whereNull('deleted_at')],
            'user_id' => ['nullable', 'uuid', Rule::exists('organisation_user', 'user_id')->where('organisation_id', $organisationId)],
        ];
    }
}
