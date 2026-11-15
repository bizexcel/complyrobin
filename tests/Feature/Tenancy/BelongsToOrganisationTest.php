<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\Exceptions\TenancyBypassDenied;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->a = Organisation::factory()->create();
    $this->b = Organisation::factory()->create();

    Tenancy::run($this->a, fn () => Department::factory()->count(2)->create());
    Tenancy::run($this->b, fn () => Department::factory()->create());
});

it('scopes queries to the current organisation', function (): void {
    expect(Tenancy::run($this->a, fn () => Department::count()))->toBe(2)
        ->and(Tenancy::run($this->b, fn () => Department::count()))->toBe(1);
});

it('fails closed without a tenant', function (): void {
    Department::count();
})->throws(MissingTenantContext::class);

it('fills organisation_id on create', function (): void {
    $department = Tenancy::run($this->a, fn () => Department::create(['name' => 'Finance']));

    expect($department->organisation_id)->toBe($this->a->id);
});

it('refuses a mismatched organisation_id', function (): void {
    Tenancy::run($this->a, function (): void {
        $department = new Department(['name' => 'x']);
        $department->organisation_id = $this->b->id; // never mass assignable
        $department->save();
    });
})->throws(TenantMismatch::class);

it('bypass sees every organisation and is logged', function (): void {
    Log::spy();

    expect(Tenancy::bypass('test', fn () => Department::count()))->toBe(3);

    Log::shouldHaveReceived('info')->with('tenancy.bypass', ['reason' => 'test']);
});

it('denies bypass when not allowed', function (): void {
    Tenancy::allowBypassUsing(fn (): false => false);

    Tenancy::bypass('test', fn () => Department::count());
})->throws(TenancyBypassDenied::class);
