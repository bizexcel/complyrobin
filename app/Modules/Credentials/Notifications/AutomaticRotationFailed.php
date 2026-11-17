<?php

namespace App\Modules\Credentials\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A runner couldn't rotate a credential, or went quiet while doing it. The current key still works.
 */
class AutomaticRotationFailed extends Notification
{
    public function __construct(public readonly string $credentialReference, public readonly string $credentialName, public readonly string $reason, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title())->line($this->body())->action('Open the credential', $this->url);
    }

    /**
     * In Filament's database-notification format, built by hand because domain code doesn't use Filament.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => $this->title(),
            'body' => $this->body(),
            'status' => 'danger',
            'icon' => 'heroicon-o-exclamation-triangle',
            'iconColor' => 'danger',
            'color' => null,
            'iconSize' => null,
            'duration' => 'persistent',
            'actions' => [],
            'view' => null,
            'viewData' => [],
        ];
    }

    public function title(): string
    {
        return "Automatic rotation failed: {$this->credentialReference}";
    }

    public function body(): string
    {
        return "{$this->credentialName} was not rotated. {$this->reason} The current key still works. Rotate it yourself, or fix the runner and press Rotate now.";
    }
}
