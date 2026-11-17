<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\UpdateOrganisationProfile;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('creates an organisation with a slug made from its name', function (): void {
    $organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme Berhad');

    expect($organisation->refresh())
        ->name->toBe('Acme Berhad')
        ->slug->toBe('acme-berhad')
        ->timezone->toBe('Asia/Kuala_Lumpur')
        ->previous_slugs->toBe([]);
});

it('numbers the slug when another organisation holds it', function (Closure $takeSlug): void {
    $takeSlug();

    expect(app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme')->slug)->toBe('acme-2');
})->with([
    'as its slug' => fn () => Organisation::factory()->create(['slug' => 'acme']),
    'as a previous slug' => fn () => Organisation::factory()->create(['previous_slugs' => ['acme']]),
    'while deleted' => function (): void {
        $organisation = Organisation::factory()->create(['slug' => 'acme']);
        Tenancy::bypass('test: delete an organisation', fn () => $organisation->delete());
    },
]);

it('never generates a reserved slug', function (): void {
    expect(app(CreateOrganisation::class)->handle(User::factory()->create(), 'Admin')->slug)->toBe('admin-2');
});

it('keeps generated slugs between 3 and 40 characters', function (string $name, string $slug): void {
    expect(app(CreateOrganisation::class)->handle(User::factory()->create(), $name)->slug)->toBe($slug);
})->with([
    'too short' => ['AB', 'ab-org'],
    'no letters' => ['???', 'org'],
    'too long' => [str_repeat('Long Name ', 6), 'long-name-long-name-long-name-long-name'],
]);

it('fits the number inside 40 characters when a long slug is taken', function (): void {
    $name = str_repeat('Long Name ', 6);
    app(CreateOrganisation::class)->handle(User::factory()->create(), $name);

    expect(app(CreateOrganisation::class)->handle(User::factory()->create(), $name)->slug)->toBe('long-name-long-name-long-name-long-nam-2');
});

it('picks the next slug when a simultaneous sign-up takes it first', function (): void {
    $raced = false;
    Organisation::creating(function (Organisation $organisation) use (&$raced): void {
        if (! $raced) {
            $raced = true;
            DB::connection('pgsql_migrator')->table('organisations')->insert([
                'id' => (string) Str::uuid7(), 'name' => 'Acme (other sign-up)', 'slug' => $organisation->slug,
            ]);
        }
    });

    expect(app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme')->slug)->toBe('acme-2')
        ->and(Organisation::query()->pluck('slug')->sort()->values()->all())->toBe(['acme', 'acme-2']);
});

it('keeps the organisation\'s country, which decides the currency it pays in', function (?string $country, string $currency): void {
    $organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme', $country);

    expect($organisation->country)->toBe($country)
        ->and($organisation->preferredCurrency())->toBe($currency);
})->with([
    'Malaysia' => ['MY', 'myr'],
    'Singapore' => ['SG', 'usd'],
    'not chosen yet' => [null, 'myr'],
]);

it('refuses a country that isn\'t on the list', function (): void {
    expect(fn () => app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme', 'XX'))
        ->toThrow(ValidationException::class);
});

it('lets the organisation change its country in its settings', function (): void {
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, 'Acme');
    $update = fn (string $country) => Tenancy::run($organisation, fn () => app(UpdateOrganisationProfile::class)
        ->handle($owner, $organisation, ['name' => 'Acme', 'timezone' => 'Asia/Kuala_Lumpur', 'country' => $country]));

    expect($update('SG')->country)->toBe('SG')
        ->and(fn () => $update('Narnia'))->toThrow(ValidationException::class);
});
