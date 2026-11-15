<?php

namespace App\Core\Audit\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Audit\AuditTrail;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Files\SafeCsv;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ExportAuditTrail
{
    /** @var list<int> */
    public const array PERIODS = [30, 90, 365];

    public function __construct(private Entitlements $entitlements) {}

    /**
     * The organisation's audit trail for the last few days (at most a year) as a CSV download, written row by row. A
     * Team feature for members who export audit evidence. The export itself goes on the trail.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, int $days = 90): StreamedResponse
    {
        Gate::forUser($actor)->authorize(Ability::ExportAudit->value);

        if (! $this->entitlements->allows(Feature::AuditExport)) {
            throw ValidationException::withMessages(['export' => 'Exporting the audit trail is part of Team. Upgrade to use it.']);
        }

        Validator::make(['days' => $days], ['days' => ['required', Rule::in(self::PERIODS)]])->validate();

        $organisation = Organisation::query()->findOrFail(Tenancy::id());
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        activity()->causedBy($actor)->performedOn($organisation)->event('exported')->log("Exported the audit trail (last {$days} days)");

        // The download is written after this returns, possibly outside the organisation: run it inside it again.
        return response()->streamDownload(function () use ($organisation, $from, $to): void {
            Tenancy::run($organisation, function () use ($organisation, $from, $to): void {
                $output = fopen('php://output', 'w');

                if ($output !== false) {
                    SafeCsv::write($output, AuditTrail::CSV_HEADER, AuditTrail::rows($from, $to, $organisation->timezone));
                    fclose($output);
                }
            });
        }, "audit-trail-{$organisation->slug}-{$to->setTimezone($organisation->timezone)->format('Y-m-d')}.csv", ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
