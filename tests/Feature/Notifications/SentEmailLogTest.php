<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\SentEmail;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\Admin\Resources\SentEmails\Pages\ListSentEmails;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * An email holding something that must never reach the log.
 */
class SignInCodeForTest extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your sign-in code')->line('Your code is 918273.');
    }
}

/**
 * @return list<array<string, mixed>>
 */
function sentEmailLog(): array
{
    return Tenancy::bypass('test: read the email log', fn (): array => SentEmail::query()->get()
        ->map(fn (SentEmail $email): array => $email->only(['to', 'subject', 'kind']))->all());
}

it('logs who each email went to, its subject and kind, but never the body', function (): void {
    $user = User::factory()->create(['email' => 'Nadia@Example.com']);

    $user->notify(new SignInCodeForTest);

    expect(sentEmailLog())->toBe([[
        'to' => 'nadia@example.com',
        'subject' => 'Your sign-in code',
        'kind' => SignInCodeForTest::class,
    ]]);

    $row = DB::connection('pgsql_platform')->table('sent_emails')->sole();
    expect(json_encode($row))->not->toContain('918273');
});

it('logs plain mail and every recipient', function (): void {
    Mail::raw('Hello', fn ($message) => $message->to('a@example.com')->cc('b@example.com')->subject('Plain'));

    expect(sentEmailLog())->toBe([['to' => 'a@example.com, b@example.com', 'subject' => 'Plain', 'kind' => null]]);
});

it('shows staff the email log, searchable by address', function (): void {
    Mail::raw('One', fn ($message) => $message->to('a@example.com')->subject('First'));
    Mail::raw('Two', fn ($message) => $message->to('b@example.com')->subject('Second'));
    [$first, $second] = Tenancy::bypass('test', fn () => SentEmail::query()->orderBy('subject')->get()->all());

    asStaff(User::factory()->create(['platform_role' => PlatformRole::Support]), fn () => Livewire::test(ListSentEmails::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->searchTable('b@example')
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first]));
});

it('lets the app record sends but never read or change the log', function (string $statement): void {
    DB::connection('pgsql')->statement($statement);
})->with([
    'select' => 'select * from sent_emails',
    'update' => "update sent_emails set subject = 'x'",
    'delete' => 'delete from sent_emails',
])->throws(QueryException::class, 'permission denied');
