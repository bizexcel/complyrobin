<?php

namespace App\Core\Notifications\Enums;

use App\Core\Entitlements\Enums\Feature;

enum ChannelType: string
{
    case Slack = 'slack';
    case Teams = 'teams';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
            self::Telegram => 'Telegram',
        };
    }

    /**
     * Slack and Teams post to a webhook URL the organisation gives; Telegram is reached through ComplyRobin's bot.
     */
    public function usesWebhook(): bool
    {
        return $this !== self::Telegram;
    }

    /**
     * Telegram chats must prove they belong to the organisation with a code the bot sends.
     */
    public function needsVerificationCode(): bool
    {
        return $this === self::Telegram;
    }

    /**
     * Whether ComplyRobin offers this kind of channel: switched on in CHAT_CHANNELS and, for Telegram, with the bot set
     * up. A channel of a type that isn't available keeps its settings but gets no reminders.
     */
    public function isAvailable(): bool
    {
        return in_array($this->value, (array) config('services.chat_channels.enabled', []), true)
            && ($this !== self::Telegram || filled(config('services.telegram.bot_token')));
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isAvailable()));
    }

    public static function anyAvailable(): bool
    {
        return self::available() !== [];
    }

    /**
     * The plan feature this kind of channel needs.
     */
    public function feature(): Feature
    {
        return Feature::ChatChannels;
    }
}
