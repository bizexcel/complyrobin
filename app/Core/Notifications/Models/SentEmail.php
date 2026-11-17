<?php

namespace App\Core\Notifications\Models;

use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One email the app sent, for the staff panel's email log. Written by RecordSentEmail and never changed; the tenant
 * role can't read it. Holds the recipients, subject and kind of email, never the body.
 *
 * @property string $to comma-separated addresses
 * @property string|null $subject
 * @property string|null $kind the notification or mailable class
 * @property string|null $message_id the mail provider's id, to trace a send with them
 * @property Carbon $created_at
 */
#[Fillable(['to', 'subject', 'kind', 'message_id'])]
class SentEmail extends Model
{
    use HasUuids, UsesTenancyConnection;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
