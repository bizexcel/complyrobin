<?php

namespace App\Core\Organisations\Notifications;

use App\Core\Organisations\Models\Organisation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the invitation link. The link holds the only copy of the token.
 */
class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organisation $organisation, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to {$this->organisation->name} on ComplyRobin")
            ->line("You've been invited to join {$this->organisation->name} on ComplyRobin.")
            ->action('Accept the invitation', $this->url)
            ->line('The link expires in 7 days. If you weren\'t expecting it, you can ignore this email.');
    }
}
