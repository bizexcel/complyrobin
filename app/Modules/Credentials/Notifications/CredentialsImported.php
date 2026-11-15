<?php

namespace App\Modules\Credentials\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * How a spreadsheet import went: how many credentials were added, and why rows were skipped (the first ten).
 */
class CredentialsImported extends Notification
{
    public const int FAILURES_SHOWN = 10;

    /**
     * @param  array<int, string>  $failures  the reason each skipped row failed, by line number
     */
    public function __construct(public readonly string $organisationName, public readonly string $url, public readonly int $imported, public readonly array $failures) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title())->line($this->summary());

        foreach ($this->failureLines() as $line) {
            $message->line($line);
        }

        return $message->action('Open the credentials register', $this->url);
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
            'body' => implode("\n", [$this->summary(), ...$this->failureLines()]),
            'status' => $this->failures === [] ? 'success' : 'warning',
            'icon' => 'heroicon-o-arrow-up-tray',
            'iconColor' => $this->failures === [] ? 'success' : 'warning',
            'color' => null,
            'iconSize' => null,
            'duration' => 'persistent',
            'actions' => [],
            'view' => null,
            'viewData' => [],
        ];
    }

    private function title(): string
    {
        return "Credential import finished at {$this->organisationName}";
    }

    private function summary(): string
    {
        $added = $this->imported === 1 ? '1 credential added' : "{$this->imported} credentials added";
        $skipped = count($this->failures);

        return $skipped === 0 ? "{$added}." : "{$added}; {$skipped} ".($skipped === 1 ? 'row' : 'rows').' skipped.';
    }

    /**
     * @return list<string>
     */
    private function failureLines(): array
    {
        $lines = array_map(fn (int $line, string $reason): string => "Row {$line}: {$reason}", array_keys($this->failures), $this->failures);
        $hidden = count($lines) - self::FAILURES_SHOWN;

        return $hidden > 0 ? [...array_slice($lines, 0, self::FAILURES_SHOWN), "…and {$hidden} more."] : $lines;
    }
}
