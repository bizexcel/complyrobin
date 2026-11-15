<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An organisation created by its owner, plus one more member added from inside it.
 *
 * @return array{Organisation, User, User}
 */
function organisationWithTeammate(string $name): array
{
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, $name);

    Tenancy::run($organisation, fn () => $organisation->users()->attach($teammate));

    return [$organisation, $owner, $teammate];
}

it('makes the creator a member of the new organisation', function (): void {
    $owner = User::factory()->create();

    app(CreateOrganisation::class)->handle($owner, 'Acme');

    $this->actingAs($owner);
    expect($owner->organisations()->pluck('slug')->all())->toBe(['acme']);
});

it('lets a user reach only the organisations they belong to', function (): void {
    [$acme, $alice] = organisationWithTeammate('Acme');
    [$globex] = organisationWithTeammate('Globex');

    $this->actingAs($alice);

    expect($alice->organisations()->pluck('slug')->all())->toBe(['acme'])
        ->and($alice->canAccessTenant($acme))->toBeTrue()
        ->and($alice->canAccessTenant($globex))->toBeFalse();
});

it('shows the database only the user\'s own memberships until an organisation is chosen', function (): void {
    [$acme, $alice, $bob] = organisationWithTeammate('Acme');
    organisationWithTeammate('Globex');

    $this->actingAs($alice);
    $visible = fn () => DB::table('organisation_user')->pluck('user_id')->sort()->values()->all();

    expect($visible())->toBe([$alice->id])
        ->and(Tenancy::run($acme, $visible))->toBe(collect([$alice->id, $bob->id])->sort()->values()->all());
});

it('stops showing memberships once the user logs out', function (): void {
    [, $alice] = organisationWithTeammate('Acme');

    $this->actingAs($alice);
    Auth::logout();

    expect(DB::table('organisation_user')->count())->toBe(0);
});

it('refuses to add a member to an organisation other than the current one', function (): void {
    [$acme] = organisationWithTeammate('Acme');
    [$globex] = organisationWithTeammate('Globex');
    $intruder = User::factory()->create();

    Tenancy::run($acme, fn () => DB::table('organisation_user')->insert([
        'id' => (string) Str::uuid7(), 'organisation_id' => $globex->id, 'user_id' => $intruder->id,
    ]));
})->throws(QueryException::class, 'row-level security');
