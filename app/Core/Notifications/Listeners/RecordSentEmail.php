<?php

namespace App\Core\Notifications\Listeners;

use App\Core\Notifications\Models\SentEmail;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;

/**
 * Writes each email the app sends to the staff panel's email log: recipients, subject, which email it was and the
 * provider's message id. Never the body. The email has already gone, so a failure to log it is reported, not thrown.
 */
final class RecordSentEmail
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        $to = collect([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()])
            ->map(fn (Address $address): string => Str::lower($address->getAddress()))
            ->unique()
            ->implode(', ');

        $kind = $event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? null;

        rescue(fn () => SentEmail::query()->create([
            'to' => $to,
            'subject' => Str::limit((string) $message->getSubject(), 250, '…'),
            'kind' => is_string($kind) ? $kind : null,
            'message_id' => $event->sent->getMessageId(),
        ]));
    }
}
