<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;

it('makes a customer set up multi-factor authentication before opening their organisation', function (): void {
    $user = User::factory()->withoutMultiFactorAuthentication()->create();
    app(CreateOrganisation::class)->handle($user, 'Acme');

    $this->actingAs($user)->get('/acme')->assertRedirect('/multi-factor-authentication/set-up');
});

it('makes staff set up multi-factor authentication before opening the admin panel', function (): void {
    $staff = User::factory()->withoutMultiFactorAuthentication()->create(['platform_role' => PlatformRole::Admin]);

    $this->actingAs($staff)->get('/admin')->assertRedirect('/admin/multi-factor-authentication/set-up');
});

it('lets a user with multi-factor authentication in', function (): void {
    $user = User::factory()->create();
    app(CreateOrganisation::class)->handle($user, 'Acme');

    $this->actingAs($user)->get('/acme')->assertOk();
});
