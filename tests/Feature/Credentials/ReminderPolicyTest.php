<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Audit\Models\Activity;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\UpdateOrganisationProfile;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\OrganisationSettings;
use App\Modules\Credentials\Actions\UpdateReminderPolicy;
use App\Modules\Credentials\Support\ReminderPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

function updatePolicy(User $actor, array $data): ReminderPolicy
{
    return Tenancy::run(test()->organisation, fn (): ReminderPolicy => app(UpdateReminderPolicy::class)->handle($actor, test()->organisation, $data));
}

it('reminds 14, 7 and 1 days before and 0, 3 and 7 days after by default, from 09:00', function (): void {
    expect(ReminderPolicy::for($this->organisation))
        ->before->toBe([14, 7, 1])
        ->overdue->toBe([0, 3, 7])
        ->sendHour->toBe(9);
});

it('saves an organisation\'s own reminder schedule', function (): void {
    updatePolicy($this->owner, ['before' => ['30', '7'], 'overdue' => ['1'], 'send_hour' => 8]);

    expect(ReminderPolicy::for($this->organisation->refresh()))
        ->before->toBe([30, 7])
        ->overdue->toBe([1])
        ->sendHour->toBe(8);
});

it('rejects a schedule that makes no sense', function (array $data): void {
    updatePolicy($this->owner, ['before' => [14, 7, 1], 'overdue' => [0, 3], 'send_hour' => 9, ...$data]);
})->with([
    'nothing before' => [['before' => []]],
    'a repeated day' => [['before' => [7, 7]]],
    'zero days before' => [['before' => [0]]],
    'more than 90 days' => [['overdue' => [91]]],
    'more than six steps' => [['before' => [1, 2, 3, 4, 5, 6, 7]]],
    'a negative day' => [['overdue' => [-1]]],
    'an hour that isn\'t one' => [['send_hour' => 24]],
])->throws(ValidationException::class);

it('lets only members who manage the organisation change its settings', function (): void {
    updatePolicy(memberWithRole($this->organisation, SystemRole::Editor), ['before' => [7], 'overdue' => [], 'send_hour' => 9]);
})->throws(AuthorizationException::class);

it('refuses a time zone that doesn\'t exist', function (): void {
    Tenancy::run($this->organisation, fn () => app(UpdateOrganisationProfile::class)->handle($this->owner, $this->organisation, ['name' => 'Acme', 'timezone' => 'Asia/Atlantis']));
})->throws(ValidationException::class);

it('records settings changes in the audit trail', function (): void {
    $this->actingAs($this->owner);
    Tenancy::run($this->organisation, fn () => app(UpdateOrganisationProfile::class)->handle($this->owner, $this->organisation, ['name' => 'Acme', 'timezone' => 'Asia/Singapore']));

    $change = Tenancy::run($this->organisation, fn () => Activity::query()->where('subject_type', $this->organisation->getMorphClass())->latest()->first());

    expect($change->attribute_changes['attributes']['timezone'])->toBe('Asia/Singapore')
        ->and($change->causer_id)->toBe($this->owner->id);
});

it('saves the settings page', function (): void {
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(OrganisationSettings::class)
        ->fillForm(['name' => 'Acme Group', 'timezone' => 'Asia/Jakarta', 'country' => 'ID', 'reminders_before' => ['21', '7'], 'reminders_overdue' => ['0', '2'], 'reminders_send_hour' => 10])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->organisation->refresh())->name->toBe('Acme Group')->timezone->toBe('Asia/Jakarta')->country->toBe('ID')
        ->and(ReminderPolicy::for($this->organisation)->before)->toBe([21, 7]);
});

it('keeps the settings page from members who can\'t manage the organisation', function (): void {
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation);

    Livewire::test(OrganisationSettings::class)->assertNotFound();
});
