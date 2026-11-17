<?php

namespace App\Billing;

use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One verified Airwallex webhook event, stored once by its event id. The app may only add these; staff read them.
 *
 * @property string $airwallex_id
 * @property string $name
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
#[Table('billing_webhook_calls')]
class WebhookCall extends Model
{
    use HasUuids, UsesTenancyConnection;

    public const null UPDATED_AT = null;

    /**
     * Store the event unless it was stored before. Returns whether it is new: Airwallex delivers an event at least
     * once, so a repeat is acknowledged without being handled again.
     *
     * @param  array{id: string, name: string}&array<string, mixed>  $payload
     */
    public static function record(array $payload): bool
    {
        return static::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'airwallex_id' => $payload['id'],
            'name' => $payload['name'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]) === 1;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
