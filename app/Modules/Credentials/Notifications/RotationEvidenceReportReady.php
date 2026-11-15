<?php

namespace App\Modules\Credentials\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the member who asked that the rotation evidence report is ready. Links to the reports page, never the file.
 */
class RotationEvidenceReportReady extends Notification
{
    public function __construct(public readonly string $organisationName, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your rotation evidence report for {$this->organisationName} is ready")
            ->line('The PDF and CSV are ready to download from ComplyRobin.')
            ->action('Open reports', $this->url);
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
            'title' => 'Your rotation evidence report is ready',
            'body' => "Download it from Reports at {$this->organisationName}.",
            'status' => 'success',
            'icon' => 'heroicon-o-document-check',
            'iconColor' => 'success',
            'color' => null,
            'iconSize' => null,
            'duration' => 'persistent',
            'actions' => [],
            'view' => null,
            'viewData' => [],
        ];
    }
}
