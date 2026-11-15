<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\RenameOrganisationSlug;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\CalendarFeed as CalendarFeedPage;
use App\Modules\Credentials\Actions\CreateCalendarFeed;
use App\Modules\Credentials\Actions\RevokeCalendarFeed;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\CalendarFeed;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

function calendarLinkFor(User $user, ?Organisation $organisation = null): string
{
    return Tenancy::run($organisation ?? test()->organisation, fn (): string => app(CreateCalendarFeed::class)->handle($user));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function credentialInCalendar(array $attributes = [], int $dueInDays = 10): Credential
{
    return Tenancy::run(test()->organisation, fn (): Credential => Credential::factory()->dueIn($dueInDays)->create([
        'application_id' => Application::factory()->create()->id,
        ...$attributes,
    ])->refresh());
}

function personFor(User $user): Person
{
    return Tenancy::run(test()->organisation, fn (): Person => Person::factory()->create(['user_id' => $user->id, 'email' => $user->email]));
}

/**
 * The calendar with folded lines joined back up.
 */
function unfolded(string $calendar): string
{
    return str_replace("\r\n ", '', $calendar);
}

it('serves a member the credentials they own or back up, on any plan', function (): void {
    $member = memberWithRole($this->organisation, SystemRole::Viewer);
    $person = personFor($member);
    $owned = credentialInCalendar(['owner_id' => $person->id]);
    $backedUp = credentialInCalendar(['backup_owner_id' => $person->id]);
    $someoneElses = credentialInCalendar(['owner_id' => Tenancy::run($this->organisation, fn () => Person::factory()->create()->id)]);

    $response = $this->get(calendarLinkFor($member));

    $response->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $calendar = unfolded($response->getContent());
    expect($calendar)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")->toEndWith("END:VCALENDAR\r\n")
        ->and(substr_count($calendar, 'BEGIN:VEVENT'))->toBe(2)
        ->and($calendar)->toContain($owned->reference)->toContain($backedUp->reference)->not->toContain($someoneElses->reference)
        ->and(preg_match("/(?<!\r)\n/", $response->getContent()))->toBe(0);
});

it('shows every active credential to members who manage credentials', function (): void {
    $editor = memberWithRole($this->organisation, SystemRole::Editor);
    $active = credentialInCalendar();
    $revoked = credentialInCalendar(['revoked_at' => now()]);

    $calendar = $this->get(calendarLinkFor($editor))->assertOk()->getContent();

    expect(unfolded($calendar))->toContain($active->reference)->not->toContain($revoked->reference);
});

it('puts each rotation on its due date in the organisation\'s time zone', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 20:00', 'UTC'));
    $credential = credentialInCalendar(['rotation_days' => 30, 'last_rotated_at' => now()->subDays(30)]);

    $calendar = $this->get(calendarLinkFor($this->owner))->assertOk()->getContent();

    // 20:00 UTC on 1 June is already 2 June in Kuala Lumpur.
    expect($credential->next_rotation_at->toDateTimeString())->toBe('2026-06-01 20:00:00')
        ->and($calendar)->toContain("DTSTART;VALUE=DATE:20260602\r\n")->toContain("DTEND;VALUE=DATE:20260603\r\n");
});

it('escapes text and folds long lines without splitting a character', function (): void {
    credentialInCalendar(['name' => 'Payouts, refunds; and "café" webhooks for the Malaysian and Singapore storefronts \\ v2']);

    $calendar = $this->get(calendarLinkFor($this->owner))->assertOk()->getContent();

    foreach (explode("\r\n", $calendar) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }

    expect(unfolded($calendar))->toContain('SUMMARY:Rotate Payouts\, refunds\; and "café" webhooks for the Malaysian and Singapore storefronts \\\\ v2 (CRD-');
});

it('never puts the hint, where it is used or notes in the calendar', function (): void {
    credentialInCalendar(['hint' => 'k9Qz', 'used_in' => 'STRIPE_SECRET in the production environment']);
    Tenancy::run($this->organisation, fn () => Application::query()->update(['notes' => 'Ask Siti for the recovery codes']));

    $calendar = unfolded($this->get(calendarLinkFor($this->owner))->assertOk()->getContent());

    expect($calendar)->not->toContain('k9Qz')->not->toContain('STRIPE_SECRET')->not->toContain('recovery codes');
});

it('stops working when the link is replaced or switched off', function (): void {
    $first = calendarLinkFor($this->owner);
    $second = calendarLinkFor($this->owner);

    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();

    Tenancy::run($this->organisation, fn () => app(RevokeCalendarFeed::class)->handle($this->owner));

    $this->get($second)->assertNotFound();
});

it('stops working when the member leaves the organisation', function (): void {
    $member = memberWithRole($this->organisation, SystemRole::Editor);
    $link = calendarLinkFor($member);

    Tenancy::run($this->organisation, fn () => $this->organisation->users()->detach($member));

    $this->get($link)->assertNotFound();
});

it('is a 404 for another organisation\'s link under this organisation\'s address', function (): void {
    $globexOwner = User::factory()->create();
    $globex = app(CreateOrganisation::class)->handle($globexOwner, 'Globex');
    $globexLink = calendarLinkFor($globexOwner, $globex);

    $this->get($globexLink)->assertOk();
    $this->get(str_replace("/calendar/{$globex->slug}/", "/calendar/{$this->organisation->slug}/", $globexLink))->assertNotFound();
});

it('keeps working after the organisation\'s address is renamed', function (): void {
    $link = calendarLinkFor($this->owner);

    Tenancy::run($this->organisation, fn () => app(RenameOrganisationSlug::class)->handle($this->owner, $this->organisation, 'acme-group'));

    $this->get($link)->assertOk();
});

it('stores only a hash of the link', function (): void {
    $link = calendarLinkFor($this->owner);
    $token = Str::of($link)->afterLast('/')->before('.ics')->toString();

    $stored = DB::connection('pgsql_migrator')->table('calendar_feeds')->value('token');

    expect($stored)->toBe(hash('sha256', $token))->not->toBe($token)
        ->and(json_encode(Tenancy::run($this->organisation, fn () => CalendarFeed::query()->sole())))->not->toContain($stored);
});

it('records when the link was last used, at most once an hour and outside the audit trail', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    $link = calendarLinkFor($this->owner);
    $lastUsed = fn (): ?string => DB::connection('pgsql_migrator')->table('calendar_feeds')->value('last_used_at');
    $audited = fn (): int => DB::connection('pgsql_migrator')->table('activities')->where('subject_type', (new CalendarFeed)->getMorphClass())->count();
    $auditedBefore = $audited();

    $this->get($link)->assertOk();
    $first = $lastUsed();
    $this->travel(30)->minutes();
    $this->get($link)->assertOk();
    $unchanged = $lastUsed();
    $this->travel(31)->minutes();
    $this->get($link)->assertOk();

    expect($first)->not->toBeNull()->and($unchanged)->toBe($first)
        ->and($lastUsed())->not->toBe($first)
        ->and($audited())->toBe($auditedBefore);
});

it('limits how often a link can be fetched', function (): void {
    $link = calendarLinkFor($this->owner);

    foreach (range(1, 60) as $fetch) {
        $this->get($link)->assertOk();
    }

    $this->get($link)->assertTooManyRequests();
});

it('needs no session or cookies', function (): void {
    $response = $this->get(calendarLinkFor($this->owner))->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

it('shows a new link once on the calendar page, and switches it off', function (): void {
    openOrganisationAs($this->owner, $this->organisation);

    $page = Livewire::test(CalendarFeedPage::class)->assertSee('You have no calendar link yet.')->callAction('createLink');
    $link = $page->get('newLink');

    expect($link)->toStartWith(url("/calendar/{$this->organisation->slug}/"));
    $page->assertSee($link);
    Livewire::test(CalendarFeedPage::class)->assertDontSee($link)->assertSee('hasn\'t been used');
    $this->get($link)->assertOk();

    Livewire::test(CalendarFeedPage::class)->callAction('revokeLink')->assertSee('You have no calendar link yet.');
    $this->get($link)->assertNotFound();
});
