<?php

namespace App\Core\Notifications\Policies;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationChannel;

/**
 * Team chat channels are managed by members with `channels.manage`. Plan features are checked in the Actions.
 */
class NotificationChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Ability::ManageChannels->value);
    }

    public function view(User $user, NotificationChannel $channel): bool
    {
        return $user->can(Ability::ManageChannels->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Ability::ManageChannels->value);
    }

    public function update(User $user, NotificationChannel $channel): bool
    {
        return $user->can(Ability::ManageChannels->value);
    }

    public function delete(User $user, NotificationChannel $channel): bool
    {
        return $user->can(Ability::ManageChannels->value);
    }
}
