<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\RotationRunStatus;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Models\Runner;
use App\Modules\Credentials\Support\RotationFailures;
use App\Modules\Credentials\Support\RunnerTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ClaimRotationRuns
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * A runner checking in: hand it the credentials it should rotate now, each as a new run. Null when the token isn't
     * one of the organisation's active runners.
     *
     * Due means past its due date and not failed in the last few hours, or someone pressed Rotate now. A credential
     * is never handed out while a run of it is in progress. Runs a runner never reported on are given up on first,
     * and their owners told.
     *
     * @return array{jobs: list<array<string, mixed>>}|null
     */
    public function handle(Organisation $organisation, string $token): ?array
    {
        return Tenancy::run($organisation, function () use ($token): ?array {
            $runner = RunnerTokens::find($token);

            if (! $runner instanceof Runner) {
                return null;
            }

            $abandoned = [];

            $jobs = DB::transaction(function () use ($runner, &$abandoned): array {
                Runner::query()->whereKey($runner->getKey())->toBase()->update(['last_seen_at' => now()]);

                $abandoned = $this->abandonSilentRuns();

                if (! $this->entitlements->allows(Feature::AutomaticRotation)) {
                    return [];
                }

                $jobs = [];

                foreach ($this->due($runner) as $automaticRotation) {
                    $run = $this->claim($automaticRotation);

                    if ($run instanceof RotationRun) {
                        $jobs[] = $this->job($automaticRotation, $run);
                    }
                }

                return $jobs;
            });

            foreach ($abandoned as $run) {
                RotationFailures::notify($run);
            }

            return ['jobs' => $jobs];
        });
    }

    /**
     * @return list<RotationRun>
     */
    private function abandonSilentRuns(): array
    {
        $silent = RotationRun::query()
            ->where('status', RotationRunStatus::Claimed)
            ->where('claimed_at', '<', now()->subMinutes((int) config('credentials.automatic_rotation.claim_expires_minutes')))
            ->lockForUpdate()
            ->get();

        foreach ($silent as $run) {
            $run->forceFill(['status' => RotationRunStatus::Abandoned, 'finished_at' => now(), 'failure' => 'The runner did not report back.'])->save();
        }

        return $silent->all();
    }

    /**
     * @return iterable<AutomaticRotation>
     */
    private function due(Runner $runner): iterable
    {
        $retryAfter = now()->subHours((int) config('credentials.automatic_rotation.retry_after_hours'));

        return AutomaticRotation::query()
            ->with('credential')
            ->where('runner_id', $runner->getKey())
            ->whereHas('credential', fn (Builder $query) => $query->whereNull('revoked_at'))
            ->whereDoesntHave('runs', fn (Builder $query) => $query->where('status', RotationRunStatus::Claimed))
            ->where(fn (Builder $query) => $query
                ->whereNotNull('requested_at')
                ->orWhere(fn (Builder $query) => $query
                    ->whereHas('credential', fn (Builder $query) => $query->where('next_rotation_at', '<=', now()))
                    ->whereDoesntHave('runs', fn (Builder $query) => $query
                        ->whereIn('status', [RotationRunStatus::Failed, RotationRunStatus::Abandoned])
                        ->where('finished_at', '>', $retryAfter))))
            ->orderBy(Credential::query()->select('next_rotation_at')->whereColumn('credentials.id', 'automatic_rotations.credential_id'))
            ->limit((int) config('credentials.automatic_rotation.max_jobs_per_claim'))
            ->lockForUpdate()
            ->get();
    }

    /**
     * Start a run, unless another claim started one for this credential at the same moment.
     */
    private function claim(AutomaticRotation $automaticRotation): ?RotationRun
    {
        $id = DB::scalar(<<<'SQL'
            insert into rotation_runs (id, organisation_id, credential_id, runner_id, status, claimed_at)
            values (?, ?, ?, ?, ?, ?)
            on conflict (organisation_id, credential_id) where status = 'claimed' do nothing
            returning id
            SQL, [
            (string) Str::uuid7(), Tenancy::id(), $automaticRotation->credential_id, $automaticRotation->runner_id,
            RotationRunStatus::Claimed->value, now(),
        ]);

        if (! is_string($id)) {
            return null;
        }

        if ($automaticRotation->requested_at !== null) {
            $automaticRotation->forceFill(['requested_at' => null])->save();
        }

        return RotationRun::query()->findOrFail($id);
    }

    /**
     * The job in the runner contract's shape (see the design spec).
     *
     * @return array<string, mixed>
     */
    private function job(AutomaticRotation $automaticRotation, RotationRun $run): array
    {
        $credential = $automaticRotation->credential;

        return [
            'run' => $run->getKey(),
            'credential' => ['reference' => $credential?->reference, 'name' => $credential?->name],
            'source' => ['type' => $automaticRotation->source->value, ...$automaticRotation->source_settings],
            'destinations' => $automaticRotation->destinations,
            'grace_hours' => (int) config('credentials.automatic_rotation.grace_hours'),
        ];
    }
}
