<?php

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\ChatMessage;
use App\Core\Notifications\Enums\ChannelType;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Notifications\Rules\AllowedWebhookUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts a message to a team chat channel's webhook. The URL is checked again on every send, redirects are never
 * followed, and errors never include the service's reply, which could carry anything.
 */
final class ChatDelivery
{
    /**
     * @throws ChatDeliveryFailed
     */
    public function send(NotificationChannel $channel, ChatMessage $message): void
    {
        $url = (string) ($channel->settings['webhook_url'] ?? '');

        if (! AllowedWebhookUrl::allows($channel->type, $url)) {
            throw new ChatDeliveryFailed("The {$channel->type->label()} channel's webhook URL isn't allowed.");
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->acceptJson()
                ->post($url, self::payload($channel->type, $message));
        } catch (ConnectionException) {
            throw new ChatDeliveryFailed("Couldn't reach {$channel->type->label()}.");
        }

        if (! $response->successful()) {
            throw new ChatDeliveryFailed("{$channel->type->label()} didn't accept the message (HTTP {$response->status()}).");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(ChannelType $type, ChatMessage $message): array
    {
        $link = $message->url === null ? '' : "\n<{$message->url}|Open in ComplyRobin>";

        return match ($type) {
            ChannelType::Slack => [
                'text' => "*{$message->title}*\n".implode("\n", $message->lines).$link,
            ],
            ChannelType::Teams => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [
                            ['type' => 'TextBlock', 'text' => $message->title, 'weight' => 'Bolder', 'wrap' => true],
                            ...array_map(fn (string $line): array => ['type' => 'TextBlock', 'text' => $line, 'wrap' => true], $message->lines),
                        ],
                        'actions' => $message->url === null ? [] : [['type' => 'Action.OpenUrl', 'title' => 'Open in ComplyRobin', 'url' => $message->url]],
                    ],
                ]],
            ],
        };
    }
}
