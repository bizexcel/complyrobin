<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\RotationDestination;
use App\Modules\Credentials\Enums\RotationSource;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Rules\NotASecret;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

final readonly class SetAutomaticRotation
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Switch on automatic rotation for a credential, or replace how it is done: which runner, where it makes the key
     * and where it puts it. Ids only; anything that looks like a key is refused, and settings it doesn't know are
     * dropped. A Team feature.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential, array $data): AutomaticRotation
    {
        Gate::forUser($actor)->authorize('update', $credential);

        if (! $this->entitlements->allows(Feature::AutomaticRotation)) {
            throw ValidationException::withMessages(['runner_id' => 'Automatic rotation is part of the Team plan.']);
        }

        $validated = Validator::make($data, [
            'runner_id' => ['required', 'uuid', Rule::exists('runners', 'id')->where('organisation_id', Tenancy::id())->whereNull('revoked_at')],
            'source' => ['required', Rule::enum(RotationSource::class)],
            'source_settings' => ['required', 'array'],
            'source_settings.project_id' => ['required', 'string', 'max:100', new NotASecret],
            'source_settings.service_account_id' => ['required', 'string', 'max:100', new NotASecret],
            'source_settings.key_lifetime_days' => [$credential->expires_at === null ? 'nullable' : 'required', 'integer', 'min:1', 'max:365'],
            'destinations' => ['required', 'array', 'min:1', 'max:5'],
            'destinations.*.type' => ['required', Rule::enum(RotationDestination::class)],
            'destinations.*.secret_id' => ['required', 'string', 'max:100', new NotASecret],
            'destinations.*.secret_name' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'destinations.*.environment_ids' => ['nullable', 'array', 'max:10'],
            'destinations.*.environment_ids.*' => ['string', 'max:100', new NotASecret],
            'destinations.*.redeploy' => ['required', 'boolean'],
        ], [
            'runner_id.exists' => 'Choose one of your active runners.',
            'source_settings.key_lifetime_days.required' => 'This credential expires: say how many days each new key lasts.',
            'destinations.*.secret_name.regex' => 'Use the variable\'s name as your app reads it, such as OPENAI_API_KEY.',
        ])->after(function (ValidatorInstance $validator) use ($credential, $data): void {
            if ($credential->revoked_at !== null) {
                $validator->errors()->add('runner_id', 'A revoked credential can\'t be rotated.');
            } elseif ($credential->rotation_days === null) {
                $validator->errors()->add('runner_id', 'Give this credential a rotation schedule first.');
            }

            foreach ((array) ($data['destinations'] ?? []) as $index => $destination) {
                if (is_array($destination) && filter_var($destination['redeploy'] ?? false, FILTER_VALIDATE_BOOLEAN) && empty($destination['environment_ids'])) {
                    $validator->errors()->add("destinations.{$index}.environment_ids", 'Choose the environments to redeploy.');
                }
            }
        })->validate();

        $settings = [
            'project_id' => $validated['source_settings']['project_id'],
            'service_account_id' => $validated['source_settings']['service_account_id'],
            'key_lifetime_days' => isset($validated['source_settings']['key_lifetime_days']) ? (int) $validated['source_settings']['key_lifetime_days'] : null,
        ];

        $destinations = array_map(fn (array $destination): array => [
            'type' => (string) $destination['type'],
            'secret_id' => (string) $destination['secret_id'],
            'secret_name' => (string) $destination['secret_name'],
            'environment_ids' => array_values(array_map(strval(...), $destination['environment_ids'] ?? [])),
            'redeploy' => filter_var($destination['redeploy'], FILTER_VALIDATE_BOOLEAN),
        ], array_values($validated['destinations']));

        return DB::transaction(fn (): AutomaticRotation => AutomaticRotation::query()->updateOrCreate(
            ['credential_id' => $credential->getKey()],
            ['runner_id' => $validated['runner_id'], 'source' => $validated['source'], 'source_settings' => $settings, 'destinations' => $destinations],
        ));
    }
}
