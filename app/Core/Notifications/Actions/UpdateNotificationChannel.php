<?php

namespace App\Core\Notifications\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Notifications\Rules\AllowedWebhookUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdateNotificationChannel
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Rename, switch on or off, or replace the webhook URL (write-only: leave it empty to keep the current one).
     * Switching off always works; switching on or changing the URL is a Team feature. A new URL needs a new test.
     *
     * @param  array{name?: string, is_enabled?: bool, webhook_url?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, NotificationChannel $channel, array $data): NotificationChannel
    {
        Gate::forUser($actor)->authorize('update', $channel);

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'is_enabled' => ['boolean'],
            'webhook_url' => ['nullable', 'string', 'max:2048', new AllowedWebhookUrl($channel->type)],
        ])->after(function ($validator) use ($channel, $data): void {
            $enabling = (bool) ($data['is_enabled'] ?? $channel->is_enabled) && ! $channel->is_enabled;

            if (($enabling || filled($data['webhook_url'] ?? null)) && ! $this->entitlements->allows($channel->type->feature())) {
                $validator->errors()->add('is_enabled', 'Team chat reminders are part of Team. Upgrade to use this channel.');
            }
        })->validate();

        $channel->fill(['name' => $validated['name'], 'is_enabled' => (bool) ($validated['is_enabled'] ?? $channel->is_enabled)]);

        if (filled($validated['webhook_url'] ?? null)) {
            $channel->settings = ['webhook_url' => $validated['webhook_url']];
            $channel->verified_at = null;
        }

        $channel->save();

        return $channel;
    }
}
