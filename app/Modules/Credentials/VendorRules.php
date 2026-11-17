<?php

namespace App\Modules\Credentials;

use App\Modules\Credentials\Enums\VendorCategory;
use App\Modules\Credentials\Models\Vendor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validation for a vendor in the catalogue. The slug is the key VendorSeeder matches on, so it is set once, when the
 * vendor is added, and never changed. Links are https only.
 */
final class VendorRules
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{slug?: string, name: string, category: string, website_url: string, rotation_url: string|null, rotation_days: int}
     */
    public static function validate(array $data, ?Vendor $vendor = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('vendors', 'name')->ignore($vendor)],
            'category' => ['required', Rule::enum(VendorCategory::class)],
            'website_url' => ['required', 'string', 'max:255', 'url:https'],
            'rotation_url' => ['nullable', 'string', 'max:255', 'url:https'],
            'rotation_days' => ['required', 'integer', 'between:1,1095'],
        ];

        if (! $vendor instanceof Vendor) {
            $rules['slug'] = ['required', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('vendors', 'slug')];
        }

        /** @var array{slug?: string, name: string, category: string, website_url: string, rotation_url: string|null, rotation_days: int} */
        return Validator::make($data, $rules, [
            'slug.regex' => 'Use lower-case letters, numbers and single hyphens, such as laravel-cloud.',
            'slug.unique' => 'Another vendor already has this slug.',
            'name.unique' => 'Another vendor already has this name.',
            'website_url.url' => 'Enter a full https:// address.',
            'rotation_url.url' => 'Enter a full https:// address.',
        ])->validate();
    }
}
