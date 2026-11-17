<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\RotationRunners;
use App\Modules\Credentials\Models\Runner;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

it('shows a new runner\'s token once, with the organisation to give the runner', function (): void {
    onTeamPlan($this->organisation);
    openOrganisationAs($this->owner, $this->organisation);

    $page = Livewire::test(RotationRunners::class)->callAction('addRunner', ['name' => 'GitHub: acme/api']);

    $runner = Tenancy::run($this->organisation, fn (): Runner => Runner::query()->sole());
    expect($page->get('newToken'))->toMatch('/^crr_/')
        ->and($runner->name)->toBe('GitHub: acme/api');
    $page->assertSee('Copy this token now. For your security it is shown only once.')
        ->assertSee($page->get('newToken'))
        ->assertSee('Organisation: '.$this->organisation->slug)
        ->assertCanSeeTableRecords([$runner]);

    Livewire::test(RotationRunners::class)
        ->assertDontSee('Copy this token now.')
        ->assertDontSee($page->get('newToken'));
});

it('revokes a runner', function (): void {
    onTeamPlan($this->organisation);
    ['runner' => $runner] = runnerFor($this->organisation, $this->owner);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(RotationRunners::class)->callAction(TestAction::make('revoke')->table($runner));

    expect($runner->refresh()->revoked_at)->not->toBeNull();
});

it('marks runners that stopped checking in as silent', function (): void {
    onTeamPlan($this->organisation);
    ['runner' => $runner] = runnerFor($this->organisation, $this->owner);
    $seen = fn (int $hoursAgo): Runner => Tenancy::run($this->organisation, function () use ($runner, $hoursAgo): Runner {
        $runner->forceFill(['last_seen_at' => now()->subHours($hoursAgo)])->save();

        return $runner;
    });

    expect(RotationRunners::status($runner))->toBe('Active');

    $this->travel(25)->hours();

    expect(RotationRunners::status($runner))->toBe('Silent')
        ->and(RotationRunners::status($seen(47)))->toBe('Active')
        ->and(RotationRunners::status($seen(49)))->toBe('Silent');
});

it('is only for members who manage credentials', function (): void {
    onTeamPlan($this->organisation);
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);

    Livewire::test(RotationRunners::class)->assertForbidden();
});

it('says automatic rotation is part of the Team plan on Free', function (): void {
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(RotationRunners::class)
        ->assertSee('Automatic rotation is part of the Team plan.')
        ->assertActionHidden('addRunner');
});
