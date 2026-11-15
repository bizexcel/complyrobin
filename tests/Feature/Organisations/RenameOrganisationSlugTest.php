<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\RenameOrganisationSlug;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An organisation with the given slug (and previous slugs), and its owner.
 *
 * @param  list<string>  $previousSlugs
 * @return array{Organisation, User}
 */
function ownedOrganisation(string $slug, array $previousSlugs = []): array
{
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, $slug);
    DB::connection('pgsql_migrator')->table('organisations')->where('id', $organisation->id)
        ->update(['slug' => $slug, 'previous_slugs' => json_encode($previousSlugs)]);

    return [$organisation->refresh(), $owner];
}

function renameSlug(User $actor, Organisation $organisation, string $slug): Organisation
{
    return Tenancy::run($organisation, fn () => app(RenameOrganisationSlug::class)->handle($actor, $organisation, $slug));
}

it('renames the slug and remembers the old one', function (): void {
    [$organisation, $owner] = ownedOrganisation('acme');

    renameSlug($owner, $organisation, 'acme-group');

    expect($organisation->refresh())
        ->slug->toBe('acme-group')
        ->previous_slugs->toBe(['acme']);
});

it('lets an organisation take back its own previous slug', function (): void {
    [$organisation, $owner] = ownedOrganisation('acme-group', ['acme']);

    renameSlug($owner, $organisation, 'acme');

    expect($organisation->refresh())
        ->slug->toBe('acme')
        ->previous_slugs->toBe(['acme-group']);
});

it('lets an admin rename but not a viewer', function (): void {
    [$organisation] = ownedOrganisation('acme');
    [$admin, $viewer] = User::factory()->count(2)->create();
    Tenancy::run($organisation, function () use ($organisation, $admin, $viewer): void {
        $organisation->users()->attach([$admin->id, $viewer->id]);
        $admin->assignRole(SystemRole::Admin->value);
        $viewer->assignRole(SystemRole::Viewer->value);
    });

    renameSlug($admin, $organisation, 'acme-group');

    expect(fn (): Organisation => renameSlug($viewer, $organisation, 'acme-viewer'))->toThrow(AuthorizationException::class)
        ->and($organisation->refresh()->slug)->toBe('acme-group');
});

it('rejects a slug that is malformed, reserved or used by another organisation', function (string $slug): void {
    ownedOrganisation('taken');
    ownedOrganisation('other', ['once-taken']);
    [$organisation, $owner] = ownedOrganisation('acme');

    try {
        renameSlug($owner, $organisation, $slug);
        $this->fail("Accepted the slug [{$slug}].");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('slug')
            ->and($organisation->refresh()->slug)->toBe('acme');
    }
})->with([
    'too short' => 'ab',
    'too long' => str_repeat('a', 41),
    'capitals' => 'Acme',
    'spaces' => 'acme group',
    'leading hyphen' => '-acme',
    'trailing hyphen' => 'acme-',
    'double hyphen' => 'acme--group',
    'reserved' => 'admin',
    'reserved prefix' => 'livewire-abc123',
    'another organisation\'s slug' => 'taken',
    'another organisation\'s previous slug' => 'once-taken',
]);

it('refuses to rename an organisation other than the current one', function (): void {
    [$current, $owner] = ownedOrganisation('acme');
    [$other] = ownedOrganisation('globex');

    expect(fn () => Tenancy::run($current, fn () => app(RenameOrganisationSlug::class)->handle($owner, $other, 'hijacked')))
        ->toThrow(TenantMismatch::class)
        ->and($other->refresh()->slug)->toBe('globex');
});

it('refuses to rename without a current organisation', function (): void {
    [$organisation, $owner] = ownedOrganisation('acme');

    app(RenameOrganisationSlug::class)->handle($owner, $organisation, 'renamed');
})->throws(MissingTenantContext::class);
