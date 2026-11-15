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
     * Whether ComplyRobin is set up to use this kind of channel.
     */
    public function isAvailable(): bool
    {
        return $this !== self::Telegram || filled(config('services.telegram.bot_token'));
    }

    /**
     * The plan feature this kind of channel needs.
     */
    public function feature(): Feature
    {
        return Feature::ChatChannels;
    }
}
