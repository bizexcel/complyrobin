<?php

namespace App\Modules\Credentials\Support;

use App\Core\Organisations\Models\Organisation;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The register at a glance, for the current organisation, on its own days (like the credential list's filters):
 * overdue, due within the due-soon window, the share of the last 90 days' rotations done on time, and active
 * credentials whose reminders have no owner to go to.
 */
final readonly class RegisterStatistics
{
    public const int ON_TIME_WINDOW_DAYS = 90;

    public function __construct(
        public int $overdue,
        public int $dueSoon,
        public ?int $onTimePercent,
        public int $rotations,
        public int $withoutReachableOwner,
    ) {}

    public static function for(Organisation $organisation, CarbonInterface $now): self
    {
        $startOfToday = RotationSchedule::startOfDay($now, $organisation->timezone);
        $dueSoonEnd = RotationSchedule::startOfDay($now, $organisation->timezone, (int) config('credentials.due_soon_days') + 1);

        $rotations = CredentialRotation::query()
            ->where('rotated_at', '>=', CarbonImmutable::instance($now)->utc()->subDays(self::ON_TIME_WINDOW_DAYS))
            ->get(['id', 'rotated_at', 'due_at']);
        $onTime = $rotations->filter(fn (CredentialRotation $rotation): bool => $rotation->isOnTime($organisation->timezone))->count();

        return new self(
            overdue: Credential::query()->active()->where('next_rotation_at', '<', $startOfToday)->count(),
            dueSoon: Credential::query()->active()->where('next_rotation_at', '>=', $startOfToday)->where('next_rotation_at', '<', $dueSoonEnd)->count(),
            onTimePercent: $rotations->isEmpty() ? null : (int) floor($onTime * 100 / $rotations->count()),
            rotations: $rotations->count(),
            withoutReachableOwner: self::withoutReachableOwner($organisation)->count(),
        );
    }

    /**
     * Active credentials with no owner, a deleted owner, or an owner with neither an email nor a membership here: the
     * same test reminders use, so these are the credentials whose reminders fall back to the Owners and Admins.
     *
     * @return Builder<Credential>
     */
    public static function withoutReachableOwner(Organisation $organisation): Builder
    {
        $members = DB::table('organisation_user')->where('organisation_id', $organisation->getKey())->select('user_id');

        return Credential::query()->active()->where(fn (Builder $query) => $query
            ->whereDoesntHave('owner')
            ->orWhereHas('owner', fn (Builder $owner) => $owner
                ->where(fn (Builder $email) => $email->whereNull('email')->orWhere('email', ''))
                ->where(fn (Builder $login) => $login->whereNull('user_id')->orWhereNotIn('user_id', $members))));
    }
}
