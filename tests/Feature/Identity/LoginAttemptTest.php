<?php

use App\Core\Identity\Enums\LoginAttemptStatus;
use App\Core\Identity\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Login attempts as platform staff see them (the app's own role can't read the table).
 *
 * @return list<array{email: string, status: string, user_id: string|null}>
 */
function recordedLoginAttempts(): array
{
    return DB::connection('pgsql_platform')->table('login_attempts')->orderBy('created_at')
        ->get(['email', 'status', 'user_id'])->map(fn (object $row): array => (array) $row)->all();
}

it('records a failed sign-in with the email that was tried', function (string $email, bool $isKnown): void {
    $user = User::factory()->withoutMultiFactorAuthentication()->create(['email' => 'bob@example.com']);
    Filament::setCurrentPanel('app');

    Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(recordedLoginAttempts())->toBe([[
        'email' => $email,
        'status' => LoginAttemptStatus::Failed->value,
        'user_id' => $isKnown ? $user->id : null,
    ]]);
})->with([
    'known email' => ['bob@example.com', true],
    'unknown email' => ['nobody@example.com', false],
]);

it('records a successful sign-in', function (): void {
    $user = User::factory()->create();

    Auth::login($user);

    expect(recordedLoginAttempts())->toBe([[
        'email' => $user->email,
        'status' => LoginAttemptStatus::Succeeded->value,
        'user_id' => $user->id,
    ]]);
});

it('keeps login attempts unreadable to the app\'s own database role', function (): void {
    Auth::login(User::factory()->create());

    DB::connection('pgsql')->table('login_attempts')->count();
})->throws(QueryException::class, 'permission denied');
