<?php

namespace App\Core\Entitlements\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Entitlements\Models\ModuleGrant;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class GrantModule
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Grant a module to a root organisation (and so its sub-organisations) for a period. Platform Admins only.
     * Any module puts the organisation on Team; pass Plan::Pro or Plan::Enterprise with the credentials module for those.
     *
     * @throws AuthorizationException
     */
    public function handle(User $staff, Organisation $organisation, Module $module, CarbonInterface $startsAt, ?CarbonInterface $endsAt = null, ?string $note = null, ?Plan $plan = null): ModuleGrant
    {
        if ($staff->platform_role !== PlatformRole::Admin) {
            throw new AuthorizationException('Only a platform Admin can grant modules.');
        }

        if ($organisation->root_id !== null) {
            throw new InvalidArgumentException('Grant modules to the root organisation; its sub-organisations share them.');
        }

        if ($plan instanceof Plan && ($module !== Module::Credentials || $plan === Plan::Free)) {
            throw new InvalidArgumentException('Only the credentials module carries a plan, and it is Pro, Team or Enterprise.');
        }

        if ($endsAt instanceof CarbonInterface && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('A grant must end after it starts.');
        }

        $grant = Tenancy::bypass("grant {$module->value} to an organisation", fn (): ModuleGrant => tap(
            new ModuleGrant([
                'module' => $module,
                'plan' => $plan,
                'granted_by_id' => $staff->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'note' => $note,
            ]),
            function (ModuleGrant $grant) use ($organisation): void {
                $grant->forceFill(['organisation_id' => $organisation->getKey()])->save();
            },
        ));

        $this->entitlements->forget($organisation);

        return $grant;
    }
}
