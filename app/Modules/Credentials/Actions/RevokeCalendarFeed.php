<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\CalendarFeed;

final readonly class RevokeCalendarFeed
{
    /**
     * Switch off the member's own calendar link. Calendars subscribed to it stop updating.
     */
    public function handle(User $actor): void
    {
        CalendarFeed::query()->where('user_id', $actor->getKey())->first()?->delete();
    }
}
