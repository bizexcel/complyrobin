<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;

it('makes an existing account an Admin from the server console', function (): void {
    $aminah = User::factory()->create();

    $this->artisan('staff:grant', ['email' => $aminah->email])
        ->assertSuccessful()
        ->expectsOutputToContain("{$aminah->email} is now Admin.");

    expect($aminah->refresh()->platform_role)->toBe(PlatformRole::Admin)
        ->and(staffRoleChanges()->sole()->changed_by_id)->toBeNull();
});

it('makes someone Support with --role=support', function (): void {
    $aminah = User::factory()->create();

    $this->artisan('staff:grant', ['email' => $aminah->email, '--role' => 'support'])->assertSuccessful();

    expect($aminah->refresh()->platform_role)->toBe(PlatformRole::Support);
});

it('refuses a role that doesn\'t exist', function (): void {
    $this->artisan('staff:grant', ['email' => User::factory()->create()->email, '--role' => 'owner'])
        ->assertFailed()
        ->expectsOutputToContain('Choose --role=admin or --role=support.');
});

it('explains why it can\'t make someone staff', function (): void {
    $this->artisan('staff:grant', ['email' => 'nobody@example.com'])
        ->assertFailed()
        ->expectsOutputToContain('No ComplyRobin account uses that email.');
});

it('removes someone from staff, but never the last Admin', function (): void {
    $admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $support = User::factory()->create(['platform_role' => PlatformRole::Support]);

    $this->artisan('staff:revoke', ['email' => $support->email])
        ->assertSuccessful()
        ->expectsOutputToContain("{$support->email} is no longer staff.");
    $this->artisan('staff:revoke', ['email' => $admin->email])
        ->assertFailed()
        ->expectsOutputToContain('ComplyRobin must keep at least one Admin.');

    expect([$support->refresh()->platform_role, $admin->refresh()->platform_role])->toBe([null, PlatformRole::Admin]);
});
