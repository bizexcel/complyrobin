<?php

namespace App\Core\Notifications\Rules;

use App\Core\Notifications\Enums\ChannelType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A webhook URL ComplyRobin may post to: https on the standard port, no user details, on the chat service's own host.
 * Anything else could point our servers at an internal address (server-side request forgery).
 */
final readonly class AllowedWebhookUrl implements ValidationRule
{
    public function __construct(private ChannelType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($this->type, $value)) {
            $fail("This isn't a {$this->type->label()} webhook URL. Copy it from {$this->type->label()} exactly.");
        }
    }

    public static function allows(ChannelType $type, string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        return match ($type) {
            ChannelType::Slack => in_array($host, (array) config('services.chat_webhooks.slack_hosts'), true),
            ChannelType::Teams => array_any(
                (array) config('services.chat_webhooks.teams_host_suffixes'),
                fn (string $suffix): bool => str_ends_with($host, $suffix) && strlen($host) > strlen($suffix),
            ),
        };
    }
}
