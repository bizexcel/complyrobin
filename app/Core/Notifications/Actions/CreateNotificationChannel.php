<?php

namespace App\Core\Notifications\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels\ChatDelivery;
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
     * Add a team chat channel. A Team feature. It gets reminders once proven: a test message for webhooks, a code
     * typed back for Telegram (anyone could name someone else's Telegram chat).
     *
     * @param  array{type?: string, name?: string, webhook_url?: string, chat_id?: string}  $data
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
            'webhook_url' => $type?->usesWebhook() === false ? ['prohibited'] : ['required', 'string', 'max:2048', ...($type === null ? [] : [new AllowedWebhookUrl($type)])],
            'chat_id' => $type === ChannelType::Telegram ? ['required', 'string', 'regex:'.ChatDelivery::TELEGRAM_CHAT_ID] : ['prohibited'],
        ], [
            'chat_id.regex' => 'A Telegram chat id is a number, like 123456789 or -1001234567890.',
        ])->after(function ($validator) use ($type): void {
            if ($type !== null && ! $type->isAvailable()) {
                $validator->errors()->add('type', "{$type->label()} isn't available yet.");
            }

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
            'settings' => $type === ChannelType::Telegram ? ['chat_id' => $validated['chat_id']] : ['webhook_url' => $validated['webhook_url']],
            'is_enabled' => true,
        ]));
    }
}
