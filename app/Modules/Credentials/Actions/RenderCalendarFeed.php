<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\CalendarFeed;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialCalendar;
use Illuminate\Support\Facades\Gate;

final readonly class RenderCalendarFeed
{
    /**
     * The calendar behind a member's private link, or null when the link isn't (or is no longer) valid: unknown,
     * replaced, revoked, from another organisation, or its member has left. Members who manage credentials see every
     * active credential; others see the ones they own or back up.
     */
    public function handle(Organisation $organisation, string $token): ?string
    {
        return Tenancy::run($organisation, function () use ($organisation, $token): ?string {
            $feed = CalendarFeed::query()->with('user')->where('token', hash('sha256', $token))->first();
            $user = $feed?->user;

            if (! $feed instanceof CalendarFeed || ! $user instanceof User || ! $organisation->users()->whereKey($user->getKey())->exists()) {
                return null;
            }

            if ($feed->last_used_at === null || $feed->last_used_at->lt(now()->subHour())) {
                CalendarFeed::query()->whereKey($feed->getKey())->toBase()->update(['last_used_at' => now()]);
            }

            $credentials = Credential::query()->active()->whereNotNull('next_rotation_at')->with('application')->orderBy('next_rotation_at');

            if (! Gate::forUser($user)->allows(Ability::ManageCredentials->value)) {
                $people = Person::query()->where('user_id', $user->getKey())->pluck('id');
                $credentials->where(fn ($query) => $query->whereIn('owner_id', $people)->orWhereIn('backup_owner_id', $people));
            }

            return CredentialCalendar::render(
                $organisation->name,
                $organisation->timezone,
                $credentials->get(),
                fn (Credential $credential): string => route('filament.app.resources.credentials.view', ['tenant' => $organisation->slug, 'record' => $credential]),
                now(),
            );
        });
    }
}
