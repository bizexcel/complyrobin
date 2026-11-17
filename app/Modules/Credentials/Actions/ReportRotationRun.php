<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\RotationRunStatus;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Models\Runner;
use App\Modules\Credentials\Rules\NotASecret;
use App\Modules\Credentials\Support\RotationFailures;
use App\Modules\Credentials\Support\RotationWriter;
use App\Modules\Credentials\Support\RunnerTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class ReportRotationRun
{
    public const array STEPS = ['mint', 'push', 'deploy', 'retire'];

    public function __construct(private Entitlements $entitlements) {}

    /**
     * A runner reporting how a run went. A success is recorded as the credential's rotation, exactly once however
     * often it is reported, even after the run was given up on. A failure is stored and the owners are told. Nothing
     * that looks like a key is accepted. Null when the token isn't one of the organisation's active runners.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function handle(Organisation $organisation, string $token, array $data): ?array
    {
        return Tenancy::run($organisation, function () use ($token, $data): ?array {
            $runner = RunnerTokens::find($token);

            if (! $runner instanceof Runner) {
                return null;
            }

            if (! $this->entitlements->allows(Feature::AutomaticRotation)) {
                return ['status' => 403, 'body' => ['message' => 'Automatic rotation is part of the Team plan.']];
            }

            $validated = $this->validate($data);
            $failedRun = null;

            $outcome = DB::transaction(function () use ($runner, $validated, &$failedRun): array {
                $run = RotationRun::query()->lockForUpdate()->find($validated['run']);

                if (! $run instanceof RotationRun || $run->runner_id !== $runner->getKey()) {
                    return ['status' => 404, 'body' => ['message' => 'This runner has no such run.']];
                }

                if ($validated['outcome'] === 'failed') {
                    return $this->recordFailure($run, $validated, $failedRun);
                }

                return $this->recordSuccess($run, $runner, $validated, $failedRun);
            });

            if ($failedRun instanceof RotationRun) {
                RotationFailures::notify($failedRun);
            }

            if (isset($outcome['errors'])) {
                throw ValidationException::withMessages($outcome['errors']);
            }

            return $outcome;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $data): array
    {
        return Validator::make($data, [
            'run' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(['succeeded', 'failed'])],
            'rotated_at' => ['required_if:outcome,succeeded', 'nullable', 'date'],
            'new_key' => ['required_if:outcome,succeeded', 'nullable', 'array'],
            'new_key.id' => ['required_if:outcome,succeeded', 'nullable', 'string', 'max:100', new NotASecret],
            'new_key.hint' => ['nullable', 'string', 'max:'.(int) config('credentials.hint_max_length'), new NotASecret],
            'new_key.expires_at' => ['nullable', 'date', 'after:rotated_at'],
            'old_keys_expire_at' => ['nullable', 'date'],
            'deployments' => ['nullable', 'array', 'max:20'],
            'deployments.*' => ['string', 'max:100', new NotASecret],
            'step' => ['required_if:outcome,failed', 'nullable', Rule::in(self::STEPS)],
            'message' => ['required_if:outcome,failed', 'nullable', 'string', 'max:500', new NotASecret],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    private function recordFailure(RotationRun $run, array $validated, ?RotationRun &$failedRun): array
    {
        if ($run->status === RotationRunStatus::Succeeded) {
            return $this->conflict();
        }

        if ($run->status === RotationRunStatus::Failed) {
            return $this->recorded();
        }

        $wasClaimed = $run->status === RotationRunStatus::Claimed;

        $run->forceFill([
            'status' => RotationRunStatus::Failed,
            'finished_at' => $run->finished_at ?? now(),
            'failed_step' => $validated['step'],
            'failure' => $validated['message'],
        ])->save();

        // A run already given up on has told its owners once.
        if ($wasClaimed) {
            $failedRun = $run;
        }

        return $this->recorded();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: int, body: array<string, mixed>}|array{errors: array<string, string>}
     */
    private function recordSuccess(RotationRun $run, Runner $runner, array $validated, ?RotationRun &$failedRun): array
    {
        if ($run->status === RotationRunStatus::Succeeded) {
            return $this->recorded();
        }

        if ($run->status === RotationRunStatus::Failed) {
            return $this->conflict();
        }

        $credential = Credential::query()->withTrashed()->lockForUpdate()->findOrFail($run->credential_id);

        if ($credential->revoked_at !== null || $credential->trashed()) {
            $run->forceFill(['status' => RotationRunStatus::Failed, 'finished_at' => now(), 'failure' => 'The credential was revoked before the run finished.'])->save();
            $failedRun = $run;

            return ['errors' => ['run' => 'This credential was revoked or deleted, so the rotation wasn\'t recorded.']];
        }

        $rotatedAt = Carbon::parse($validated['rotated_at']);
        $skew = (int) config('credentials.automatic_rotation.clock_skew_minutes');

        if ($rotatedAt->lt($run->claimed_at->copy()->subMinutes($skew)) || $rotatedAt->gt(now()->addMinutes($skew))) {
            return ['errors' => ['rotated_at' => 'The rotation time must be between when the run was claimed and now.']];
        }

        if ($credential->last_rotated_at !== null && $rotatedAt->lte($credential->last_rotated_at)) {
            return ['errors' => ['rotated_at' => 'This credential was last rotated later than that.']];
        }

        $expiresAt = isset($validated['new_key']['expires_at']) ? Carbon::parse($validated['new_key']['expires_at']) : null;

        if ($credential->expires_at !== null && ! $expiresAt instanceof Carbon) {
            return ['errors' => ['new_key.expires_at' => 'This credential expires: send the new key\'s expiry date.']];
        }

        $rotation = RotationWriter::write($credential, null, $rotatedAt->min(now()), $expiresAt, "Rotated automatically by the runner \"{$runner->name}\".");

        if (filled($validated['new_key']['hint'] ?? null)) {
            $credential->forceFill(['hint' => $validated['new_key']['hint']])->save();
        }

        $run->forceFill([
            'status' => RotationRunStatus::Succeeded,
            'finished_at' => now(),
            'credential_rotation_id' => $rotation->getKey(),
            'new_key_id' => $validated['new_key']['id'],
            'new_key_hint' => $validated['new_key']['hint'] ?? null,
            'old_keys_expire_at' => $validated['old_keys_expire_at'] ?? null,
            'deployments' => array_values($validated['deployments'] ?? []),
            'failed_step' => null,
            'failure' => null,
        ])->save();

        return $this->recorded();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function recorded(): array
    {
        return ['status' => 200, 'body' => ['recorded' => true]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function conflict(): array
    {
        return ['status' => 409, 'body' => ['message' => 'This run has already been reported.']];
    }
}
