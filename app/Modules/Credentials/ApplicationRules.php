<?php

namespace App\Modules\Credentials;

use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\Criticality;
use App\Modules\Credentials\Models\Application;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Validation for an application. People are only accepted from the current organisation, and the name is unique in it
 * (ignoring case).
 */
final class ApplicationRules
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function validate(array $data, ?Application $ignore = null): array
    {
        $organisationId = Tenancy::id();
        $person = fn () => Rule::exists('people', 'id')->where('organisation_id', $organisationId)->whereNull('deleted_at');

        return Validator::make($data, [
            'vendor_id' => ['nullable', 'uuid', Rule::exists('vendors', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'criticality' => ['required', Rule::enum(Criticality::class)],
            'owner_id' => ['nullable', 'uuid', $person()],
            'backup_owner_id' => ['nullable', 'uuid', 'different:owner_id', $person()],
            'console_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ], [
            'backup_owner_id.different' => 'The backup owner must be someone other than the owner.',
        ])->after(function (ValidatorInstance $validator) use ($data, $ignore): void {
            $taken = Application::query()
                ->whereRaw('lower(name) = lower(?)', [(string) ($data['name'] ?? '')])
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', 'Another application already has this name.');
            }
        })->validate();
    }
}
