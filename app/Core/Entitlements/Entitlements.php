<?php

namespace App\Core\Entitlements;

use App\Billing\Subscription;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Entitlements\Enums\Limit;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Entitlements\Models\ModuleGrant;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Core\Tenancy\Scopes\OrganisationScope;
use Illuminate\Support\Facades\Cache;

/**
 * Which plan the current organisation is on, and which modules it may use. Entitlements belong to the root organisation and are shared by its
 * sub-organisations. A module comes from a manual grant or from a valid subscription of that type (Cashier's `subscribed()`).
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
        return array_values(array_unique(array_map(
            fn (array $grant): Module => Module::from($grant['module']),
            $this->activeGrants(),
        ), SORT_REGULAR));
    }

    /**
     * The highest plan among the grants and subscriptions: Enterprise or Pro when granted so, Team for any other grant
     * or an ISO module (which includes it), the plan of the price a subscription is on; otherwise Free.
     */
    public function plan(): Plan
    {
        return array_reduce(
            $this->activeGrants(),
            fn (Plan $highest, array $grant): Plan => ($plan = Plan::from($grant['plan'] ?? Plan::Team->value))->includes($highest) ? $plan : $highest,
            Plan::Free,
        );
    }

    /**
     * The plan a subscription gives, from the price it is on in config/billing.php. A price that isn't listed there
     * (one that was retired) gives Team, as every subscription did before Pro.
     */
    public static function planOf(Subscription $subscription): Plan
    {
        foreach ((array) config("billing.prices.{$subscription->type}", []) as $plan => $currencies) {
            foreach ((array) $currencies as $intervals) {
                foreach ((array) $intervals as $price) {
                    if (is_string($price) && $price !== '' && $subscription->hasPrice($price)) {
                        return Plan::tryFrom((string) $plan) ?? Plan::Team;
                    }
                }
            }
        }

        return Plan::Team;
    }

    public function allows(Feature $feature): bool
    {
        return $this->plan()->allows($feature);
    }

    /**
     * The most the current plan allows, or null for unlimited.
     */
    public function limit(Limit $limit): ?int
    {
        return $this->plan()->limit($limit);
    }

    /**
     * The root organisation's grants that are active now, and its valid subscriptions as grants of the plan their price gives.
     *
     * @return list<array{module: string, plan: string|null}>
     */
    private function activeGrants(): array
    {
        $rootId = $this->rootIdOf(Tenancy::id());

        /** @var list<array{module: string, plan: string|null}> */
        return Cache::remember($this->cacheKey($rootId), self::CACHE_SECONDS, fn (): array => [
            ...$this->grantedModules($rootId),
            ...$this->subscribedModules($rootId),
        ]);
    }

    /**
     * @return list<array{module: string, plan: string|null}>
     */
    private function grantedModules(string $rootId): array
    {
        return ModuleGrant::query()
            // The root's grants, which row-level security lets its sub-organisations read.
            ->withoutGlobalScope(OrganisationScope::class)
            ->where('organisation_id', $rootId)
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get(['module', 'plan'])
            ->map(fn (ModuleGrant $grant): array => ['module' => $grant->module->value, 'plan' => $grant->plan?->value])
            ->values()
            ->all();
    }

    /**
     * @return list<array{module: string, plan: string|null}>
     */
    private function subscribedModules(string $rootId): array
    {
        return Subscription::query()
            // The root's subscriptions, which row-level security lets its sub-organisations read.
            ->withoutGlobalScope(OrganisationScope::class)
            ->where('organisation_id', $rootId)
            ->whereIn('type', array_column(Module::cases(), 'value'))
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->valid())
            ->map(fn (Subscription $subscription): array => ['module' => $subscription->type, 'plan' => self::planOf($subscription)->value])
            ->values()
            ->all();
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
