<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Limit;
use App\Core\Identity\Models\User;
use App\Core\Numbering\Numbering;
use App\Modules\Credentials\CredentialRules;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class CreateCredential
{
    public function __construct(private Numbering $numbering, private Entitlements $entitlements) {}

    /**
     * Add a credential to the register, with the next reference (CRD-0001…), within the plan's limit.
     *
     * The reference is taken first: its counter row stays locked until the transaction ends, so two people adding the
     * last allowed credential at the same moment are served one after the other and can't both slip past the limit.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): Credential
    {
        Gate::forUser($actor)->authorize('create', Credential::class);

        $validated = CredentialRules::validate($data);

        return DB::transaction(function () use ($validated): Credential {
            $reference = $this->numbering->next('credential', 'CRD');
            $limit = $this->entitlements->limit(Limit::Credentials);

            if ($limit !== null && Credential::query()->active()->count() >= $limit) {
                throw ValidationException::withMessages([
                    'name' => "Your plan allows {$limit} credentials. Revoke one you no longer use, or upgrade for more.",
                ]);
            }

            $credential = new Credential($validated);
            $credential->forceFill(['reference' => $reference])->save();

            return $credential->refresh();
        });
    }
}
