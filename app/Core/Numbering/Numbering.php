<?php

namespace App\Core\Numbering;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues the human references people quote (CRD-0042, INC-2026-0007) for the current organisation. One atomic
 * upsert per number, so simultaneous requests never get the same one. Yearly series restart on 1 January in the
 * organisation's own time zone.
 */
final class Numbering
{
    public function next(string $series, string $prefix, bool $restartsYearly = false, int $digits = 4): string
    {
        $organisationId = Tenancy::id();
        $year = $restartsYearly
            ? now(Organisation::query()->findOrFail($organisationId)->timezone)->year
            : null;

        $value = (int) DB::scalar(<<<'SQL'
            insert into counters (id, organisation_id, name, year, value, created_at, updated_at)
            values (?, ?, ?, ?, 1, now(), now())
            on conflict (organisation_id, name, year) do update set value = counters.value + 1, updated_at = now()
            returning value
            SQL, [(string) Str::uuid7(), $organisationId, $series, $year]);

        $number = str_pad((string) $value, $digits, '0', STR_PAD_LEFT);

        return $year === null ? "{$prefix}-{$number}" : "{$prefix}-{$year}-{$number}";
    }
}
