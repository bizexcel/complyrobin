<?php

namespace App\Core\Notifications;

/**
 * A short message for a team chat channel: a title, a few lines, and a link into ComplyRobin.
 */
final readonly class ChatMessage
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(public string $title, public array $lines, public ?string $url = null) {}
}
