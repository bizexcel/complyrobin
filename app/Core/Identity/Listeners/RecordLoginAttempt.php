<?php

namespace App\Core\Identity\Listeners;

use App\Core\Identity\Enums\LoginAttemptStatus;
use App\Core\Identity\Models\LoginAttempt;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Records every sign-in and failed sign-in (wrong password, unknown email, or no access to the panel).
 */
final readonly class RecordLoginAttempt
{
    public function __construct(private Request $request) {}

    public function handle(Login|Failed $event): void
    {
        $email = $event instanceof Login
            ? $event->user->getAttribute('email')
            : ($event->credentials['email'] ?? $event->user?->getAttribute('email'));

        LoginAttempt::query()->create([
            'user_id' => $event->user?->getAuthIdentifier(),
            'email' => Str::lower((string) $email),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            'status' => $event instanceof Login ? LoginAttemptStatus::Succeeded : LoginAttemptStatus::Failed,
        ]);
    }
}
