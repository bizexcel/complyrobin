<?php

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Audit\Models\Activity;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

arch()->preset()->php();

arch()->preset()->security();

// One expectation per existing namespace: Pest silently skips an array expectation when any
// namespace in it doesn't exist yet, so namespaces are checked as their folders appear.
// Exception: User implements Filament's FilamentUser contract, which Filament requires for panel access.
foreach (['Core', 'Grc', 'Modules', 'Billing'] as $area) {
    if (is_dir(dirname(__DIR__, 2).'/app/'.$area)) {
        arch("App\\{$area} never touches Filament")
            ->expect("App\\{$area}")
            ->not->toUse('Filament')
            ->ignoring(User::class);
    }
}

arch('shared GRC code never depends on framework modules')
    ->expect('App\Grc')
    ->not->toUse('App\Modules');

it('audits every tenant model except the audit entries themselves', function (): void {
    foreach (appModels() as $model) {
        $traits = class_uses_recursive($model);

        if (array_key_exists(BelongsToOrganisation::class, $traits) && $model !== Activity::class) {
            expect(array_key_exists(LogsTenantActivity::class, $traits))->toBeTrue("{$model} must use LogsTenantActivity");
        }
    }
});

it('lets tenancy choose every model\'s connection, never the connection it was loaded on', function (): void {
    foreach (appModels() as $model) {
        $instance = new $model;
        $instance->setConnection('pgsql_platform');

        expect($instance->getConnectionName())->toBeNull("{$model} must use UsesTenancyConnection");
    }
});

it('gives every Eloquent model a uuid key', function (): void {
    $models = appModels();

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(array_key_exists(HasUuids::class, class_uses_recursive($model)))->toBeTrue("{$model} must use HasUuids");
    }
});
