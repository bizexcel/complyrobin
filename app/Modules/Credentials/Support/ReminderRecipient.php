<?php

namespace App\Modules\Credentials\Support;

use App\Core\Identity\Models\User;
use App\Core\People\Models\Person;
use App\Modules\Credentials\Enums\ReminderAudience;

/**
 * Who a reminder goes to: a person (who may have no login) or a member.
 */
final readonly class ReminderRecipient
{
    public function __construct(public ReminderAudience $audience, public ?Person $person = null, public ?User $user = null) {}

    /**
     * One digest goes to each distinct recipient per run.
     */
    public function key(): string
    {
        return $this->person instanceof Person ? "person:{$this->person->getKey()}" : 'user:'.$this->user?->getKey();
    }
}
