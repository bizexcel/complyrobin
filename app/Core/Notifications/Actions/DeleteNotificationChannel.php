<?php

namespace App\Core\Notifications\Actions;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class DeleteNotificationChannel
{
    /**
     * Remove a channel. Always allowed, whatever the plan.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, NotificationChannel $channel): void
    {
        Gate::forUser($actor)->authorize('delete', $channel);

        $channel->delete();
    }
}
