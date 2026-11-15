<?php

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\ChatMessage;
use App\Core\Notifications\Enums\ChannelType;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Notifications\Rules\AllowedWebhookUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts a message to a team chat channel: Slack and Teams through the webhook the organisation gave (checked again on
 * every send), Telegram through ComplyRobin's bot. Redirects are never followed, and errors never include the
 * service's reply, which could carry anything, or the request URL, which for Telegram holds the bot token.
 */
final class ChatDelivery
{
    public const string TELEGRAM_CHAT_ID = '/^-?\d{5,20}$/';

    /**
     * @throws ChatDeliveryFailed
     */
    public function send(NotificationChannel $channel, ChatMessage $message): void
    {
        [$url, $payload] = $channel->type === ChannelType::Telegram
            ? $this->telegramRequest($channel, $message)
            : $this->webhookRequest($channel, $message);

        try {
            $response = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->acceptJson()->post($url, $payload);
        } catch (Throwable) {
            // Never pass the exception on: for Telegram its message would contain the bot token.
            throw new ChatDeliveryFailed("Couldn't reach {$channel->type->label()}.");
        }

        if (! $response->successful()) {
            throw new ChatDeliveryFailed("{$channel->type->label()} didn't accept the message (HTTP {$response->status()}).");
        }
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function webhookRequest(NotificationChannel $channel, ChatMessage $message): array
    {
        $url = (string) ($channel->settings['webhook_url'] ?? '');

        if (! AllowedWebhookUrl::allows($channel->type, $url)) {
            throw new ChatDeliveryFailed("The {$channel->type->label()} channel's webhook URL isn't allowed.");
        }

        return [$url, self::payload($channel->type, $message)];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function telegramRequest(NotificationChannel $channel, ChatMessage $message): array
    {
        $token = (string) config('services.telegram.bot_token');
        $chatId = (string) ($channel->settings['chat_id'] ?? '');

        if ($token === '' || preg_match(self::TELEGRAM_CHAT_ID, $chatId) !== 1) {
            throw new ChatDeliveryFailed('Telegram isn\'t set up for this channel.');
        }

        return ["https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chatId, ...self::payload(ChannelType::Telegram, $message)]];
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
            ChannelType::Telegram => [
                'text' => $message->title."\n".implode("\n", $message->lines).($message->url === null ? '' : "\n{$message->url}"),
                'disable_web_page_preview' => true,
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
