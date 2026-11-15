<?php

namespace App\Core\Notifications\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels\ChatDelivery;
use App\Core\Notifications\Channels\ChatDeliveryFailed;
use App\Core\Notifications\ChatMessage;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Notifications\VerificationCodes;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class SendTestMessage
{
    public const int PER_MINUTE = 5;

    public function __construct(private Entitlements $entitlements, private ChatDelivery $delivery, private VerificationCodes $verificationCodes) {}

    /**
     * Post a test message to the channel. When it's accepted, the channel is verified and starts getting reminders.
     * A Telegram channel gets a code instead, which must be typed back (VerifyNotificationChannel).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, NotificationChannel $channel): NotificationChannel
    {
        Gate::forUser($actor)->authorize('update', $channel);

        if (! $this->entitlements->allows($channel->type->feature())) {
            throw ValidationException::withMessages(['channel' => 'Team chat reminders are part of Team. Upgrade to use this channel.']);
        }

        $key = 'channel-test:'.Tenancy::id();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages(['channel' => 'Too many test messages. Try again in a minute.']);
        }

        RateLimiter::hit($key, 60);

        if ($channel->type->needsVerificationCode()) {
            $this->verificationCodes->send($channel);

            return $channel;
        }

        try {
            $this->delivery->send($channel, new ChatMessage(
                'ComplyRobin test message',
                ['This channel will now get credential rotation reminders.'],
            ));
        } catch (ChatDeliveryFailed $exception) {
            throw ValidationException::withMessages(['channel' => $exception->getMessage()]);
        }

        $channel->forceFill(['verified_at' => now()])->save();

        return $channel;
    }
}
