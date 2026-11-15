<?php

namespace App\Core\Notifications;

use App\Core\Notifications\Channels\ChatDelivery;
use App\Core\Notifications\Channels\ChatDeliveryFailed;
use App\Core\Notifications\Models\NotificationChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Codes that prove a Telegram chat belongs to the organisation: the bot sends one to the chat and someone there types
 * it back. Codes last 10 minutes and are stored only as a hash; 5 wrong tries lock the code, and at most 3 codes are
 * sent an hour.
 */
final readonly class VerificationCodes
{
    public const int MINUTES_VALID = 10;

    public const int MAX_ATTEMPTS = 5;

    public const int CODES_PER_HOUR = 3;

    public function __construct(private ChatDelivery $delivery) {}

    /**
     * @throws ValidationException
     */
    public function send(NotificationChannel $channel): void
    {
        $sendKey = "channel-code-sent:{$channel->getKey()}";

        if (RateLimiter::tooManyAttempts($sendKey, self::CODES_PER_HOUR)) {
            throw ValidationException::withMessages(['channel' => 'Too many codes sent. Try again in an hour.']);
        }

        $code = (string) random_int(100000, 999999);

        try {
            $this->delivery->send($channel, new ChatMessage('Your ComplyRobin code', ["{$code} (valid for ".self::MINUTES_VALID.' minutes)']));
        } catch (ChatDeliveryFailed $exception) {
            throw ValidationException::withMessages(['channel' => $exception->getMessage()]);
        }

        RateLimiter::hit($sendKey, 3600);
        RateLimiter::clear($this->attemptsKey($channel));
        Cache::put($this->codeKey($channel), hash('sha256', $code), now()->addMinutes(self::MINUTES_VALID));
    }

    /**
     * @throws ValidationException
     */
    public function check(NotificationChannel $channel, string $code): void
    {
        $attemptsKey = $this->attemptsKey($channel);
        $expected = Cache::get($this->codeKey($channel));

        if (! is_string($expected) || RateLimiter::tooManyAttempts($attemptsKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'This code has expired or been tried too often. Send a new one.']);
        }

        if (! hash_equals($expected, hash('sha256', trim($code)))) {
            RateLimiter::hit($attemptsKey, self::MINUTES_VALID * 60);

            throw ValidationException::withMessages(['code' => 'That code isn\'t right.']);
        }

        Cache::forget($this->codeKey($channel));
        RateLimiter::clear($attemptsKey);
    }

    private function codeKey(NotificationChannel $channel): string
    {
        return "channel-code:{$channel->getKey()}";
    }

    private function attemptsKey(NotificationChannel $channel): string
    {
        return "channel-code-attempts:{$channel->getKey()}";
    }
}
