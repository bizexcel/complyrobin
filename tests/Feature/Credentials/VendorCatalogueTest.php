<?php

use App\Modules\Credentials\Models\Vendor;
use Database\Seeders\VendorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function seedVendors(): void
{
    test()->artisan('db:seed', ['--class' => VendorSeeder::class, '--database' => 'pgsql_migrator'])->assertSuccessful();
}

it('seeds the catalogue idempotently, by slug, adding missing vendors and keeping staff edits', function (): void {
    seedVendors();
    DB::connection('pgsql_migrator')->table('vendors')->where('slug', 'github')->update(['name' => 'GitHub Enterprise']);
    DB::connection('pgsql_migrator')->table('vendors')->where('slug', 'stripe')->delete();

    seedVendors();

    expect(Vendor::query()->count())->toBe(count(VendorSeeder::VENDORS))
        ->and(Vendor::query()->where('slug', 'github')->value('name'))->toBe('GitHub Enterprise')
        ->and(Vendor::query()->where('slug', 'stripe')->value('name'))->toBe('Stripe');
});

it('lets the app read the catalogue but never change it', function (string $statement): void {
    seedVendors();

    expect(Vendor::query()->where('slug', 'stripe')->exists())->toBeTrue();
    DB::connection('pgsql')->statement($statement);
})->with([
    'insert' => "insert into vendors (id, slug, name, category, website_url, rotation_days) values (gen_random_uuid(), 'evil', 'Evil', 'other', 'https://evil.example', 1)",
    'update' => "update vendors set rotation_url = 'https://evil.example'",
    'delete' => 'delete from vendors',
])->throws(QueryException::class, 'permission denied');

it('links only to https pages', function (): void {
    $urls = collect(VendorSeeder::VENDORS)->flatMap(fn (array $vendor): array => array_filter([$vendor['website_url'], $vendor['rotation_url']]));

    expect($urls->reject(fn (string $url): bool => str_starts_with($url, 'https://'))->all())->toBe([]);
});

it('seeds without calling anything outside', function (): void {
    Http::preventStrayRequests();

    seedVendors();

    expect(Vendor::query()->count())->toBeGreaterThan(0);
});
