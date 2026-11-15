<?php

namespace App\Core\Notifications\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Enums\ChannelType;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Notifications\Rules\AllowedWebhookUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CreateNotificationChannel
{
    public const int MAX_CHANNELS = 10;

    public function __construct(private Entitlements $entitlements) {}

    /**
     * Add a team chat channel. A Team feature; it gets reminders once a test message has succeeded.
     *
     * @param  array{type?: string, name?: string, webhook_url?: string}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): NotificationChannel
    {
        Gate::forUser($actor)->authorize('create', NotificationChannel::class);

        $type = ChannelType::tryFrom((string) ($data['type'] ?? ''));

        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(ChannelType::class)],
            'name' => ['required', 'string', 'max:100'],
            'webhook_url' => ['required', 'string', 'max:2048', ...($type === null ? [] : [new AllowedWebhookUrl($type)])],
        ])->after(function ($validator) use ($type): void {
            if ($type !== null && ! $this->entitlements->allows($type->feature())) {
                $validator->errors()->add('type', 'Team chat reminders are part of Team. Upgrade to add a channel.');
            }

            if (NotificationChannel::query()->count() >= self::MAX_CHANNELS) {
                $validator->errors()->add('name', 'An organisation can have at most '.self::MAX_CHANNELS.' channels.');
            }
        })->validate();

        return DB::transaction(fn (): NotificationChannel => NotificationChannel::query()->create([
            'type' => $validated['type'],
            'name' => $validated['name'],
            'settings' => ['webhook_url' => $validated['webhook_url']],
            'is_enabled' => true,
        ]));
    }
}
