<?php

use App\Core\Enquiries\Actions\MarkEnquiryHandled;
use App\Core\Enquiries\Actions\ReopenEnquiry;
use App\Core\Enquiries\Actions\SubmitEnquiry;
use App\Core\Enquiries\Models\Enquiry;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\Admin\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Admin\Resources\Enquiries\Pages\ViewEnquiry;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $this->support = User::factory()->create(['platform_role' => PlatformRole::Support]);
    $this->open = app(SubmitEnquiry::class)->handle(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'message' => 'Do you support Telegram?']);
    $this->answered = app(SubmitEnquiry::class)->handle(['name' => 'Ben Tan', 'email' => 'ben@example.com', 'message' => 'Pricing for 40 people?']);
    app(MarkEnquiryHandled::class)->handle($this->admin, $this->answered, 'Sent the Team price.');
});

it('lists open enquiries first, for any staff member', function (PlatformRole $role): void {
    $staff = User::factory()->create(['platform_role' => $role]);

    asStaff($staff, fn () => Livewire::test(ListEnquiries::class)
        ->assertCanSeeTableRecords([$this->open])
        ->assertCanNotSeeTableRecords([$this->answered])
        ->filterTable('handled', true)
        ->assertCanSeeTableRecords([$this->answered]));

    $this->get("/admin/enquiries/{$this->open->id}")->assertOk()->assertSee('Do you support Telegram?');
})->with([PlatformRole::Admin, PlatformRole::Support]);

it('lets an Admin mark an enquiry handled with a note, then reopen it', function (): void {
    asStaff($this->admin, fn () => Livewire::test(ViewEnquiry::class, ['record' => $this->open->getKey()])
        ->callAction('markHandled', ['note' => 'Replied: Telegram is on Team.'])
        ->assertHasNoActionErrors());

    $handled = Tenancy::bypass('test', fn () => $this->open->refresh());
    expect($handled->handled_at)->not->toBeNull()
        ->and($handled->handled_by_id)->toBe($this->admin->id)
        ->and($handled->note)->toBe('Replied: Telegram is on Team.');

    asStaff($this->admin, fn () => Livewire::test(ViewEnquiry::class, ['record' => $this->open->getKey()])->callAction('reopen'));

    $reopened = Tenancy::bypass('test', fn () => $this->open->refresh());
    expect([$reopened->handled_at, $reopened->handled_by_id, $reopened->note])->toBe([null, null, 'Replied: Telegram is on Team.']);
});

it('lets Support read enquiries but not handle them', function (): void {
    asStaff($this->support, fn () => Livewire::test(ViewEnquiry::class, ['record' => $this->open->getKey()])
        ->assertActionVisible('reply')
        ->assertActionHidden('markHandled'));

    expect(fn () => app(MarkEnquiryHandled::class)->handle($this->support, $this->open))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReopenEnquiry::class)->handle($this->support, $this->answered))->toThrow(AuthorizationException::class)
        ->and(Tenancy::bypass('test', fn () => Enquiry::query()->whereNull('handled_at')->count()))->toBe(1);
});
