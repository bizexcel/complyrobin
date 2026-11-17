<?php

use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Entitlements\Enums\Limit;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;

beforeEach(function (): void {
    $this->staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $this->organisation = Organisation::factory()->create();
});

/**
 * @return array{plan: Plan, members: int|null, credentials: int|null, chat: bool, evidence: bool}
 */
function planOf(Organisation $organisation): array
{
    return Tenancy::run($organisation, fn (): array => [
        'plan' => app(Entitlements::class)->plan(),
        'members' => app(Entitlements::class)->limit(Limit::Members),
        'credentials' => app(Entitlements::class)->limit(Limit::Credentials),
        'chat' => app(Entitlements::class)->allows(Feature::ChatChannels),
        'evidence' => app(Entitlements::class)->allows(Feature::EvidenceReport),
    ]);
}

it('puts an organisation without a grant on Free: 1 member, 5 credentials, email reminders only', function (): void {
    expect(planOf($this->organisation))->toBe([
        'plan' => Plan::Free, 'members' => 1, 'credentials' => 5, 'chat' => false, 'evidence' => false,
    ]);
});

it('puts an organisation granted Pro on 5 members and 100 credentials, with chat reminders but no evidence report', function (): void {
    app(GrantModule::class)->handle($this->staff, $this->organisation, Module::Credentials, now()->subDay(), plan: Plan::Pro);

    expect(planOf($this->organisation))->toBe([
        'plan' => Plan::Pro, 'members' => 5, 'credentials' => 100, 'chat' => true, 'evidence' => false,
    ]);
});

it('takes the highest plan when an organisation has more than one grant', function (): void {
    app(GrantModule::class)->handle($this->staff, $this->organisation, Module::Credentials, now()->subDay(), plan: Plan::Pro);
    app(GrantModule::class)->handle($this->staff, $this->organisation, Module::Isms, now()->subDay());

    expect(planOf($this->organisation)['plan'])->toBe(Plan::Team);
});

it('moves an organisation to Team with the credentials module, or with an ISO module, which includes it', function (Module $module): void {
    app(GrantModule::class)->handle($this->staff, $this->organisation, $module, now()->subDay());

    expect(planOf($this->organisation))->toBe([
        'plan' => Plan::Team, 'members' => 10, 'credentials' => null, 'chat' => true, 'evidence' => true,
    ]);
})->with([Module::Credentials, Module::Isms, Module::Bcms]);

it('puts an organisation granted the Enterprise plan on unlimited members', function (): void {
    app(GrantModule::class)->handle($this->staff, $this->organisation, Module::Credentials, now()->subDay(), plan: Plan::Enterprise);

    expect(planOf($this->organisation))->toMatchArray(['plan' => Plan::Enterprise, 'members' => null, 'credentials' => null]);
});

it('drops back to Free when the grant ends', function (): void {
    app(GrantModule::class)->handle($this->staff, $this->organisation, Module::Credentials, now()->subDays(30), now()->subDay());

    expect(planOf($this->organisation)['plan'])->toBe(Plan::Free);
});

it('only grants a plan with the credentials module, and never Free', function (Module $module, Plan $plan): void {
    app(GrantModule::class)->handle($this->staff, $this->organisation, $module, now(), plan: $plan);
})->with([
    'a plan on an ISO module' => [Module::Isms, Plan::Enterprise],
    'the Free plan' => [Module::Credentials, Plan::Free],
])->throws(InvalidArgumentException::class);

it('includes automatic rotation on Team and Enterprise, not on Free or Pro', function (): void {
    expect(Plan::Free->allows(Feature::AutomaticRotation))->toBeFalse()
        ->and(Plan::Pro->allows(Feature::AutomaticRotation))->toBeFalse()
        ->and(Plan::Team->allows(Feature::AutomaticRotation))->toBeTrue()
        ->and(Plan::Enterprise->allows(Feature::AutomaticRotation))->toBeTrue();
});
