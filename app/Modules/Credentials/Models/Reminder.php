<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Modules\Credentials\Enums\ReminderAudience;
use App\Modules\Credentials\Enums\ReminderKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the reminder send log. Written by ClaimDueReminders, stamped by DeliverReminders.
 *
 * @property string $credential_id
 * @property string|null $person_id
 * @property string|null $user_id
 * @property string|null $notification_channel_id
 * @property ReminderKind $kind
 * @property ReminderAudience $audience
 * @property int $offset_days
 * @property Carbon $due_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 */
class Reminder extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    public const null UPDATED_AT = null;

    /**
     * The send log is its own record; writing each entry to the audit trail too would double every reminder.
     *
     * @var list<string>
     */
    protected static array $recordEvents = [];

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function notificationChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'audience' => ReminderAudience::class,
            'offset_days' => 'integer',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
