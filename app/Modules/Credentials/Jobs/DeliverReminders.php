<?php

namespace App\Modules\Credentials\Jobs;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\Reminder;
use App\Modules\Credentials\Notifications\CredentialsDue;
use App\Modules\Credentials\Support\ReminderRecipients;
use App\Modules\Credentials\Support\RotationSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends one recipient their digest of claimed reminders, then marks them sent. Runs as the organisation it was
 * dispatched in (the tenant travels with the job). Credentials rotated or revoked since they were claimed are left out.
 */
class DeliverReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<string>  $reminderIds
     */
    public function __construct(public readonly array $reminderIds) {}

    public function handle(): void
    {
        $organisation = Organisation::query()->findOrFail(Tenancy::id());
        $reminders = Reminder::query()->whereKey($this->reminderIds)->whereNull('sent_at')->with(['credential.application', 'person.user', 'user'])->get()
            ->filter(fn (Reminder $reminder): bool => $this->isStillDue($reminder));

        $first = $reminders->first();

        if (! $first instanceof Reminder) {
            return;
        }

        $notification = new CredentialsDue(
            $organisation->name,
            route('filament.app.resources.credentials.index', ['tenant' => $organisation->slug]),
            $reminders->map(fn (Reminder $reminder): array => $this->item($reminder, $organisation->timezone))->values()->all(),
        );

        $user = $first->user ?? ($first->person !== null ? ReminderRecipients::memberOf($first->person) : null);

        if ($user instanceof User) {
            $user->notify($notification);
        } elseif (filled($first->person?->email)) {
            Notification::route('mail', $first->person->email)->notify($notification);
        } else {
            return;
        }

        Reminder::query()->whereKey($reminders->modelKeys())->update(['sent_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        Reminder::query()->whereKey($this->reminderIds)->whereNull('sent_at')->update(['failed_at' => now()]);
    }

    private function isStillDue(Reminder $reminder): bool
    {
        $credential = $reminder->credential;

        return $credential instanceof Credential
            && ! $credential->trashed()
            && $credential->revoked_at === null
            && $credential->next_rotation_at?->equalTo($reminder->due_at) === true;
    }

    /**
     * @return array{reference: string, name: string, application: string, due_on: string, days: int}
     */
    private function item(Reminder $reminder, string $timezone): array
    {
        /** @var Credential $credential */
        $credential = $reminder->credential;

        return [
            'reference' => $credential->reference,
            'name' => $credential->name,
            'application' => $credential->application->name ?? '—',
            'due_on' => $reminder->due_at->setTimezone($timezone)->format('j M Y'),
            'days' => RotationSchedule::daysUntilDue($reminder->due_at, now(), $timezone),
        ];
    }
}
