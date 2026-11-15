<?php

namespace App\Core\Entitlements;

use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Models\ModuleGrant;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Core\Tenancy\Scopes\OrganisationScope;
use Illuminate\Support\Facades\Cache;

/**
 * Which modules the current organisation may use. Entitlements belong to the root organisation and are shared by its
 * sub-organisations. Paid subscriptions join this check when billing ships.
 */
final class Entitlements
{
    private const int CACHE_SECONDS = 300;

    public function has(Module $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /**
     * @return list<Module>
     */
    public function modules(): array
    {
        $rootId = $this->rootIdOf(Tenancy::id());

        /** @var list<string> $values */
        $values = Cache::remember($this->cacheKey($rootId), self::CACHE_SECONDS, fn (): array => ModuleGrant::query()
            // The root's grants, which row-level security lets its sub-organisations read.
            ->withoutGlobalScope(OrganisationScope::class)
            ->where('organisation_id', $rootId)
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->distinct()
            ->pluck('module')
            ->map(fn (Module $module): string => $module->value)
            ->values()
            ->all());

        return array_map(Module::from(...), $values);
    }

    public function forget(Organisation $root): void
    {
        Cache::forget($this->cacheKey($root->getKey()));
    }

    private function cacheKey(string $rootId): string
    {
        return "org:{$rootId}:entitlements";
    }

    private function rootIdOf(string $organisationId): string
    {
        $organisation = Organisation::query()->findOrFail($organisationId);

        return $organisation->root_id ?? $organisation->getKey();
    }
}
