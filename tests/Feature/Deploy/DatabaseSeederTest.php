<?php

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Vendor;

it('can be run again without failing or adding a second test user', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->artisan('db:seed', ['--database' => 'pgsql_migrator'])->assertSuccessful();
    $this->artisan('db:seed', ['--database' => 'pgsql_migrator'])->assertSuccessful();

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1);
});

it('adds the test user, whose password is public, only on a developer\'s machine', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    $this->artisan('db:seed', ['--database' => 'pgsql_migrator', '--force' => true])->assertSuccessful();

    expect([User::query()->count(), Vendor::query()->exists()])->toBe([0, true]);
})->with(['production', 'preview', 'staging']);
