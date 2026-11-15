<?php

namespace App\Core\Notifications\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Notifications\Enums\ChannelType;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A team chat channel reminders can go to. Its settings (the webhook URL) are encrypted and hidden, so they are never
 * shown back, serialised or written to the audit trail.
 *
 * @property ChannelType $type
 * @property string $name
 * @property array{webhook_url?: string, chat_id?: string} $settings
 * @property bool $is_enabled
 * @property Carbon|null $verified_at
 */
#[Fillable(['type', 'name', 'settings', 'is_enabled'])]
#[Hidden(['settings'])]
class NotificationChannel extends Model
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity, SoftDeletes;

    /**
     * Enabled and proven to work by a test message.
     */
    public function isReady(): bool
    {
        return $this->is_enabled && $this->verified_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'settings' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}
