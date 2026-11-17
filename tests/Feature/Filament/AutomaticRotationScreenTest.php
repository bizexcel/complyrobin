<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Resources\Credentials\Pages\ViewCredential;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onTeamPlan($this->organisation);
    ['runner' => $this->runner] = runnerFor($this->organisation, $this->owner);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function credentialOnScreen(array $attributes = []): Credential
{
    return Tenancy::run(test()->organisation, fn (): Credential => Credential::factory()->dueIn(30)->create([
        'application_id' => Application::factory()->create()->id,
        ...$attributes,
    ]));
}

it('sets up automatic rotation from the credential\'s page', function (): void {
    $credential = credentialOnScreen();
    openOrganisationAs($this->owner, $this->organisation);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewCredential::class, ['record' => $credential->getRouteKey()])
        ->assertSee('Off. You record rotations yourself.')
        ->callAction('setUpAutomaticRotation', automaticRotationData($this->runner))
        ->assertHasNoActionErrors()
        ->assertSee('OpenAI · project proj_abc')
        ->assertSee('Laravel Cloud · OPENAI_API_KEY · redeploys 1 environment')
        ->assertSee('Not run yet');

    $undoRepeaterFake();
    expect(Tenancy::run($this->organisation, fn (): AutomaticRotation => AutomaticRotation::query()->sole())->runner_id)->toBe($this->runner->id);
});

it('asks the runner to rotate now, and switches automatic rotation off', function (): void {
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: 30);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ViewCredential::class, ['record' => $credential->getRouteKey()])->callAction('rotateNow');
    expect(Tenancy::run($this->organisation, fn (): ?Carbon => AutomaticRotation::query()->sole()->requested_at))->not->toBeNull();

    Livewire::test(ViewCredential::class, ['record' => $credential->getRouteKey()])->callAction('switchOffAutomaticRotation');
    expect(Tenancy::run($this->organisation, fn (): int => AutomaticRotation::query()->count()))->toBe(0);
});

it('hides set-up from viewers and from credentials without a rotation schedule', function (): void {
    $credential = credentialOnScreen();
    $unscheduled = credentialOnScreen(['rotation_days' => null, 'expires_at' => now()->addYear()]);

    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);
    Livewire::test(ViewCredential::class, ['record' => $credential->getRouteKey()])->assertActionHidden('setUpAutomaticRotation');

    openOrganisationAs($this->owner, $this->organisation);
    Livewire::test(ViewCredential::class, ['record' => $unscheduled->getRouteKey()])->assertActionHidden('setUpAutomaticRotation');
});
