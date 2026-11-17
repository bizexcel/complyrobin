<?php

namespace App\Modules\Credentials\Support;

use App\Core\Files\SafeCsv;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Models\Runner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Proof of rotation for auditors, for the current organisation: every rotation recorded in the period (with when it
 * was due, when it was done and recorded, whether it was on time, and its evidence files), and as exceptions the
 * credentials overdue now. Times are in the organisation's time zone. Never the hint or where a credential is used.
 */
final readonly class RotationEvidenceReport
{
    /**
     * @param  list<array{reference: string, credential: string, application: string, environment: string, due: string, rotated: string, recorded: string, on_time: bool, rotated_by: string, evidence: list<string>, notes: string}>  $rotations
     * @param  list<array{reference: string, credential: string, application: string, owner: string, due: string, days_overdue: int}>  $overdue
     */
    public function __construct(
        public string $organisation,
        public string $timezone,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $generatedAt,
        public array $rotations,
        public array $overdue,
    ) {}

    public static function build(Organisation $organisation, int $days, CarbonInterface $now): self
    {
        $timezone = $organisation->timezone;
        $from = RotationSchedule::startOfDay($now, $timezone, -$days);
        $to = CarbonImmutable::instance($now)->utc();
        $local = fn (CarbonInterface $time): string => CarbonImmutable::instance($time)->setTimezone($timezone)->format('Y-m-d H:i');

        $rotations = CredentialRotation::query()
            ->with(['credential' => fn ($query) => $query->withTrashed()->with('application'), 'rotatedBy', 'run.runner', 'media'])
            ->whereBetween('rotated_at', [$from, $to])
            ->orderBy('rotated_at')
            ->get()
            ->map(function (CredentialRotation $rotation) use ($local, $timezone): array {
                $credential = $rotation->credential;

                return [
                    'reference' => $credential instanceof Credential ? $credential->reference : '',
                    'credential' => $credential instanceof Credential ? $credential->name : '',
                    'application' => $credential?->application instanceof Application ? $credential->application->name : '',
                    'environment' => $credential instanceof Credential ? $credential->environment->label() : '',
                    'due' => $local($rotation->due_at),
                    'rotated' => $local($rotation->rotated_at),
                    'recorded' => $rotation->created_at instanceof CarbonInterface ? $local($rotation->created_at) : '',
                    'on_time' => $rotation->isOnTime($timezone),
                    'rotated_by' => self::rotatedBy($rotation),
                    'evidence' => $rotation->media->map(fn (Media $file): string => $file->file_name)->values()->all(),
                    'notes' => (string) $rotation->notes,
                ];
            })->values()->all();

        $overdue = Credential::query()->active()->with(['application', 'owner'])
            ->where('next_rotation_at', '<', RotationSchedule::startOfDay($now, $timezone))
            ->orderBy('next_rotation_at')
            ->get()
            ->map(fn (Credential $credential): array => [
                'reference' => $credential->reference,
                'credential' => $credential->name,
                'application' => $credential->application instanceof Application ? $credential->application->name : '',
                'owner' => $credential->owner instanceof Person ? $credential->owner->name : '',
                'due' => $local($credential->next_rotation_at ?? $now),
                'days_overdue' => -RotationSchedule::daysUntilDue($credential->next_rotation_at ?? $now, $now, $timezone),
            ])->values()->all();

        return new self($organisation->name, $timezone, $from->setTimezone($timezone), $to->setTimezone($timezone), CarbonImmutable::instance($now)->setTimezone($timezone), $rotations, $overdue);
    }

    /**
     * Who rotated it: the member, or the runner that did it automatically.
     */
    private static function rotatedBy(CredentialRotation $rotation): string
    {
        if ($rotation->run instanceof RotationRun) {
            return $rotation->run->runner instanceof Runner ? "Automatic rotation ({$rotation->run->runner->name})" : 'Automatic rotation';
        }

        return $rotation->rotatedBy instanceof User ? $rotation->rotatedBy->name : '';
    }

    public function onTime(): int
    {
        return count(array_filter($this->rotations, fn (array $rotation): bool => $rotation['on_time']));
    }

    public function late(): int
    {
        return count($this->rotations) - $this->onTime();
    }

    public function csv(): string
    {
        return SafeCsv::make(
            ['Reference', 'Credential', 'Application', 'Environment', "Due ({$this->timezone})", "Rotated ({$this->timezone})", "Recorded ({$this->timezone})", 'On time', 'Rotated by', 'Evidence files', 'Notes'],
            array_map(fn (array $rotation): array => [
                $rotation['reference'], $rotation['credential'], $rotation['application'], $rotation['environment'],
                $rotation['due'], $rotation['rotated'], $rotation['recorded'], $rotation['on_time'] ? 'On time' : 'Late',
                $rotation['rotated_by'], implode('; ', $rotation['evidence']), $rotation['notes'],
            ], $this->rotations),
        );
    }
}
