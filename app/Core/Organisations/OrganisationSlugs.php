<?php

namespace App\Core\Organisations;

use App\Core\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Generates and checks the slug that names an organisation in URLs (`app.complyrobin.com/{slug}`).
 * A slug is taken while any organisation, including a deleted one, uses it now or used it before.
 */
final class OrganisationSlugs
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 40;

    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * A free slug made from the name, numbered (`acme-2`, `acme-3`, …) when the plain one is taken or reserved.
     */
    public function generate(string $name): string
    {
        $base = $this->truncate(Str::slug($name), self::MAX_LENGTH);

        if (strlen($base) < self::MIN_LENGTH) {
            $base = ltrim("{$base}-org", '-');
        }

        for ($number = 1; ; $number++) {
            $suffix = $number === 1 ? '' : "-{$number}";
            $candidate = $this->truncate($base, self::MAX_LENGTH - strlen($suffix)).$suffix;

            if (! ReservedSlugs::contains($candidate) && $this->isAvailable($candidate)) {
                return $candidate;
            }
        }
    }

    /**
     * Whether no organisation other than `$except` uses the slug now or used it before.
     */
    public function isAvailable(string $slug, ?Organisation $except = null): bool
    {
        return Organisation::withTrashed()
            ->when($except, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->where(fn (Builder $query) => $query->where('slug', $slug)->orWhereJsonContains('previous_slugs', $slug))
            ->doesntExist();
    }

    private function truncate(string $slug, int $length): string
    {
        return rtrim(substr($slug, 0, $length), '-');
    }
}
