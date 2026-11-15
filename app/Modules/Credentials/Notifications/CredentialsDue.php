<?php

namespace App\Modules\Credentials\Notifications;

use App\Core\Identity\Models\User;
use App\Core\Notifications\ChatMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A digest of the credentials a recipient needs to rotate. It carries only the reference, name, application and when
 * it is due, never the hint or where it is used, and links into the organisation.
 */
class CredentialsDue extends Notification
{
    /**
     * @param  list<array{reference: string, name: string, application: string, due_on: string, days: int}>  $items
     */
    public function __construct(public readonly string $organisationName, public readonly string $url, public readonly array $items) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->line("These credentials at {$this->organisationName} need rotating:");

        foreach ($this->items as $item) {
            $message->line("{$item['reference']} · {$item['name']} ({$item['application']}): ".$this->when($item['days'], $item['due_on']));
        }

        return $message
            ->action('Open the credentials register', $this->url)
            ->line('Rotate each key with its vendor, update where it is used, then record the rotation in ComplyRobin.');
    }

    /**
     * In Filament's database-notification format, built by hand because domain code doesn't use Filament.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $overdue = array_any($this->items, fn (array $item): bool => $item['days'] < 0);

        return [
            'format' => 'filament',
            'title' => $this->title(),
            'body' => implode("\n", array_map(fn (array $item): string => "{$item['reference']} {$item['name']}: ".$this->when($item['days'], $item['due_on']), $this->items)),
            'status' => $overdue ? 'danger' : 'warning',
            'icon' => 'heroicon-o-key',
            'iconColor' => $overdue ? 'danger' : 'warning',
            'color' => null,
            'iconSize' => null,
            'duration' => 'persistent',
            'actions' => [],
            'view' => null,
            'viewData' => [],
        ];
    }

    /**
     * The same digest for a team chat channel.
     */
    public function toChatMessage(): ChatMessage
    {
        return new ChatMessage(
            $this->title(),
            array_map(fn (array $item): string => "{$item['reference']} · {$item['name']} ({$item['application']}): ".$this->when($item['days'], $item['due_on']), $this->items),
            $this->url,
        );
    }

    private function title(): string
    {
        $count = count($this->items);
        $credentials = $count === 1 ? '1 credential' : "{$count} credentials";

        return array_any($this->items, fn (array $item): bool => $item['days'] < 0)
            ? "{$credentials} overdue for rotation at {$this->organisationName}"
            : "{$credentials} due for rotation at {$this->organisationName}";
    }

    private function when(int $days, string $dueOn): string
    {
        return match (true) {
            $days < 0 => 'overdue by '.abs($days).(abs($days) === 1 ? ' day' : ' days')." (was due {$dueOn})",
            $days === 0 => "due today ({$dueOn})",
            default => "due in {$days} ".($days === 1 ? 'day' : 'days')." ({$dueOn})",
        };
    }
}
