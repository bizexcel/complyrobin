<?php

namespace App\Modules\Credentials\Support;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\People\Models\Person;
use App\Modules\Credentials\Enums\ReminderAudience;

/**
 * Who a reminder goes to: a person (who may have no login), a member, or a team chat channel.
 */
final readonly class ReminderRecipient
{
    public function __construct(
        public ReminderAudience $audience,
        public ?Person $person = null,
        public ?User $user = null,
        public ?NotificationChannel $channel = null,
    ) {}

    /**
     * One digest goes to each distinct recipient per run.
     */
    public function key(): string
    {
        return match (true) {
            $this->person instanceof Person => "person:{$this->person->getKey()}",
            $this->channel instanceof NotificationChannel => "channel:{$this->channel->getKey()}",
            default => 'user:'.$this->user?->getKey(),
        };
    }
}
