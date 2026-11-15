<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    'while deleted' => fn () => Organisation::factory()->create(['slug' => 'acme'])->delete(),
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
