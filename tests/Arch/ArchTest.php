<?php

use App\Core\Identity\Models\User;
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

it('gives every Eloquent model a uuid key', function (): void {
    $models = appModels();

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(array_key_exists(HasUuids::class, class_uses_recursive($model)))->toBeTrue("{$model} must use HasUuids");
    }
});
