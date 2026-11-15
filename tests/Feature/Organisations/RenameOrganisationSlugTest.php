<?php

use App\Core\Organisations\Actions\RenameOrganisationSlug;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Validation\ValidationException;

it('renames the slug and remembers the old one', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    Tenancy::run($organisation, fn () => app(RenameOrganisationSlug::class)->handle($organisation, 'acme-group'));

    expect($organisation->refresh())
        ->slug->toBe('acme-group')
        ->previous_slugs->toBe(['acme']);
});

it('lets an organisation take back its own previous slug', function (): void {
    $organisation = Organisation::factory()->create(['slug' => 'acme-group', 'previous_slugs' => ['acme']]);

    Tenancy::run($organisation, fn () => app(RenameOrganisationSlug::class)->handle($organisation, 'acme'));

    expect($organisation->refresh())
        ->slug->toBe('acme')
        ->previous_slugs->toBe(['acme-group']);
});

it('rejects a slug that is malformed, reserved or used by another organisation', function (string $slug): void {
    Organisation::factory()->create(['slug' => 'taken']);
    Organisation::factory()->create(['previous_slugs' => ['once-taken']]);
    $organisation = Organisation::factory()->create(['slug' => 'acme']);

    try {
        Tenancy::run($organisation, fn () => app(RenameOrganisationSlug::class)->handle($organisation, $slug));
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
    'another organisation\'s slug' => 'taken',
    'another organisation\'s previous slug' => 'once-taken',
]);

it('refuses to rename an organisation other than the current one', function (): void {
    [$current, $other] = Organisation::factory()->count(2)->create();

    expect(fn () => Tenancy::run($current, fn () => app(RenameOrganisationSlug::class)->handle($other, 'hijacked')))
        ->toThrow(TenantMismatch::class)
        ->and($other->refresh()->slug)->not->toBe('hijacked');
});

it('refuses to rename without a current organisation', function (): void {
    $organisation = Organisation::factory()->create();

    app(RenameOrganisationSlug::class)->handle($organisation, 'renamed');
})->throws(MissingTenantContext::class);
