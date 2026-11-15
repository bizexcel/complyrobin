<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\CalendarFeed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCalendarFeed
{
    /**
     * Give the member a private calendar link of upcoming rotations, replacing any link they had (which stops working).
     * Returns the link: only a hash of it is stored, so it can be shown just this once. Every plan has it.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor): string
    {
        $organisation = Organisation::query()->findOrFail(Tenancy::id());

        if (! $actor->canAccessTenant($organisation)) {
            throw new AuthorizationException;
        }

        $token = Str::random(64);

        DB::transaction(function () use ($actor, $token): void {
            CalendarFeed::query()->where('user_id', $actor->getKey())->first()?->delete();
            CalendarFeed::query()->create(['user_id' => $actor->getKey(), 'token' => hash('sha256', $token)]);
        });

        return route('calendar.feed', ['organisation' => $organisation->slug, 'token' => $token]);
    }
}
