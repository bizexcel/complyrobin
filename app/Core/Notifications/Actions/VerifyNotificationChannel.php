<?php

namespace App\Core\Notifications\Actions;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Notifications\VerificationCodes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class VerifyNotificationChannel
{
    public function __construct(private VerificationCodes $verificationCodes) {}

    /**
     * Finish setting up a Telegram channel with the code its chat received. Then it starts getting reminders.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, NotificationChannel $channel, string $code): NotificationChannel
    {
        Gate::forUser($actor)->authorize('update', $channel);

        $this->verificationCodes->check($channel, $code);
        $channel->forceFill(['verified_at' => now()])->save();

        return $channel;
    }
}
