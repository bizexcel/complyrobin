<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;

it('shows the customer login page', function (): void {
    $this->get('/login')->assertOk();
});

it('shows the admin login page', function (): void {
    $this->get('/admin/login')->assertOk();
});

it('keeps customers out of the admin panel', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('lets platform staff into the admin panel', function (): void {
    $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::Admin]))
        ->get('/admin')
        ->assertOk();
});
