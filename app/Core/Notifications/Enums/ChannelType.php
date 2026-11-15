<?php

namespace App\Core\Notifications\Enums;

use App\Core\Entitlements\Enums\Feature;

enum ChannelType: string
{
    case Slack = 'slack';
    case Teams = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
        };
    }

    /**
     * The plan feature this kind of channel needs.
     */
    public function feature(): Feature
    {
        return Feature::ChatChannels;
    }
}
