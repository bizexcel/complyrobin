<?php

namespace App\Modules\Credentials\Jobs;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\GenerateRotationEvidenceReport;
use App\Modules\Credentials\Notifications\RotationEvidenceReportReady;
use App\Modules\Credentials\Support\RotationEvidenceReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * Renders the rotation evidence report as a PDF (DOMPDF, tables only) and a CSV, and keeps both as the organisation's
 * files under its own folder. Only the latest reports are kept.
 */
class BuildRotationEvidenceReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int KEEP = 10;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly string $requestedById, public readonly int $days, public readonly string $lockOwner) {}

    public function handle(Entitlements $entitlements): void
    {
        try {
            // Downgraded since it was asked for: prepare nothing.
            if (! $entitlements->allows(Feature::EvidenceReport)) {
                return;
            }

            $organisation = Organisation::query()->findOrFail(Tenancy::id());
            $report = RotationEvidenceReport::build($organisation, $this->days, now());
            $name = 'rotation-evidence-'.$report->generatedAt->format('Y-m-d-His');

            $pdf = Pdf::view('pdf.rotation-evidence', ['report' => $report])->format('a4')->landscape()->generatePdfContent();
            $organisation->addMediaFromString($pdf)->usingFileName("{$name}.pdf")->usingName("{$name}.pdf")->toMediaCollection('rotation-evidence');
            $organisation->addMediaFromString($report->csv())->usingFileName("{$name}.csv")->usingName("{$name}.csv")->toMediaCollection('rotation-evidence');

            $organisation->getMedia('rotation-evidence')->sortByDesc('created_at')->slice(self::KEEP * 2)->each->delete();

            $requester = User::query()->find($this->requestedById);

            if ($requester instanceof User && $requester->canAccessTenant($organisation)) {
                $requester->notify(new RotationEvidenceReportReady($organisation->name, route('filament.app.pages.rotation-evidence-reports', ['tenant' => $organisation->slug])));
            }
        } finally {
            Cache::restoreLock(GenerateRotationEvidenceReport::lockName(Tenancy::id()), $this->lockOwner)->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::restoreLock(GenerateRotationEvidenceReport::lockName(Tenancy::id()), $this->lockOwner)->release();
    }
}
