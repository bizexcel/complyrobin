<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Jobs\BuildRotationEvidenceReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class GenerateRotationEvidenceReport
{
    /** @var list<int> */
    public const array PERIODS = [30, 90, 180, 365];

    public function __construct(private Entitlements $entitlements) {}

    /**
     * Prepare the rotation evidence report (PDF and CSV) for the last few days, in the queue. A Team feature for
     * members who export audit evidence; one report at a time per organisation. The requester is told when it's ready.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, int $days = 90): void
    {
        Gate::forUser($actor)->authorize(Ability::ExportAudit->value);

        if (! $this->entitlements->allows(Feature::EvidenceReport)) {
            throw ValidationException::withMessages(['report' => 'The rotation evidence report is part of Team. Upgrade to use it.']);
        }

        Validator::make(['days' => $days], ['days' => ['required', Rule::in(self::PERIODS)]])->validate();

        $lock = Cache::lock(self::lockName(Tenancy::id()), 15 * 60);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['report' => 'A report is already being prepared. It will appear here when it\'s ready.']);
        }

        BuildRotationEvidenceReport::dispatch($actor->getKey(), $days, (string) $lock->owner());
    }

    public static function lockName(string $organisationId): string
    {
        return "org:{$organisationId}:rotation-evidence-report";
    }
}
