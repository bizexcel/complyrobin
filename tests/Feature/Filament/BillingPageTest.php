<?php

use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Subscription;
use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\Billing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-17 09:34:30', 'UTC'));
    config([
        'billing.prices.credentials' => [
            'myr' => ['monthly' => 'pri_sgpvng7wjho8pcx79vl', 'yearly' => 'pri_sgpvng7wjho8pcxh39u'],
            'usd' => ['monthly' => 'pri_usd_monthly_not_recorded', 'yearly' => null],
        ],
        'billing.prices.bcms' => ['myr' => ['monthly' => null, 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => null]],
        'billing.prices.isms' => ['myr' => ['monthly' => null, 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => null]],
    ]);
    $this->owner = User::factory()->create(['email' => 'owner@acme.test']);
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme', 'MY');
});

function billingAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('module-credentials', schema: 'content');
}

function billingCustomer(Organisation $organisation): void
{
    Tenancy::run($organisation, fn () => $organisation->forceFill(['airwallex_id' => 'bcus_sgpv8sltmho8pd165e4'])->save());
}

function billingSubscription(Organisation $organisation, array $attributes = []): Subscription
{
    return Tenancy::run($organisation, fn (): Subscription => Subscription::factory()->create([
        'type' => 'credentials',
        'airwallex_id' => 'sub_sgpv4ct6kho8pd8ekaz',
        ...$attributes,
    ]));
}

it('offers the modules on sale, monthly or yearly', function (): void {
    fakeAirwallex([]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)
        ->assertSee('Credentials register')
        ->assertSee('Not subscribed')
        ->assertDontSee('Business continuity')
        ->assertActionVisible(billingAction('subscribe-credentials-monthly'))
        ->assertActionVisible(billingAction('subscribe-credentials-yearly'))
        ->assertDontSee('Cancel subscription')
        ->assertSee('No invoices yet');
    Http::assertNothingSent();
});

it('sends the organisation to Airwallex\'s checkout, coming back to this page', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_checkouts/create' => ['billing_checkouts.create', 201],
    ]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)
        ->callAction(billingAction('subscribe-credentials-yearly'))
        ->assertRedirect(airwallexFixture('billing_checkouts.create')['url']);

    $checkout = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), 'billing_checkouts/create'))->first()[0];
    expect($checkout['currency'])->toBe('MYR')
        ->and($checkout['line_items'])->toBe([['price_id' => 'pri_sgpvng7wjho8pcxh39u', 'quantity' => 1]])
        ->and($checkout['subscription_data']['trial_ends_at'])->toBe('2026-12-01T09:34:30+0000')
        ->and($checkout['subscription_data']['metadata'])->toMatchArray(['organisation_id' => $this->organisation->id, 'type' => 'credentials'])
        ->and($checkout['success_url'])->toBe(Billing::getUrl().'?checkout=success')
        ->and($checkout['back_url'])->toBe(Billing::getUrl().'?checkout=cancelled');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'billing_customers/create') && $request['email'] === 'owner@acme.test');
});

it('charges an organisation outside Malaysia in US dollars, offering only the plans priced in them', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_checkouts/create' => ['billing_checkouts.create', 201],
    ]);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['country' => 'SG'])->save());
    openOrganisationAs($this->owner, $this->organisation->fresh());

    Livewire::test(Billing::class)
        ->assertDontSee('Pay yearly')
        ->callAction(billingAction('subscribe-credentials-monthly'));

    $checkout = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), 'billing_checkouts/create'))->first()[0];
    expect($checkout['currency'])->toBe('USD')
        ->and($checkout['line_items'][0]['price_id'])->toBe('pri_usd_monthly_not_recorded');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'billing_customers/create') && $request['default_billing_currency'] === 'USD');
});

it('asks for the organisation\'s country before offering a plan', function (): void {
    fakeAirwallex([]);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['country' => null])->save());
    openOrganisationAs($this->owner, $this->organisation->fresh());

    Livewire::test(Billing::class)
        ->assertSee('Choose your organisation\'s country before you subscribe.')
        ->assertDontSee('Pay monthly');
});

it('says so when Airwallex can\'t start a checkout', function (): void {
    fakeAirwallex(['billing_customers/create' => ['authentication.login.error.bad_key', 401]]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)
        ->callAction(billingAction('subscribe-credentials-monthly'))
        ->assertNotified('Payments aren\'t available right now. Try again in a few minutes.')
        ->assertNoRedirect();
});

it('shows an active subscription and cancels it at the end of the period', function (): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pd8ekaz/update' => ['subscriptions.update.cancel_at_period_end']]);
    billingSubscription($this->organisation);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)
        ->assertSee('Active. It renews automatically.')
        ->assertDontSee('Pay monthly')
        ->callAction(billingAction('cancel-credentials'))
        ->assertNotified('Subscription cancelled. It works until 17 Dec 2026.')
        ->assertSee('Cancelled. It works until 17 Dec 2026.')
        ->assertDontSee('Cancel subscription');
});

it('lists the invoices Airwallex rendered, with links to view and download them', function (): void {
    fakeAirwallex(['invoices*' => ['invoices.list']]);
    billingCustomer($this->organisation);
    billingSubscription($this->organisation);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)
        ->assertSee('INV-PDRGVU8O-0001')
        ->assertSee('17 Nov 2026')
        ->assertSee('49.00')
        ->assertSee('Unpaid')
        ->assertSee('https://invoice.sandbox.airwallex.com/pay', escape: false)
        ->assertSee('https://invoice.sandbox.airwallex.com/pdf', escape: false);
})->skip(! extension_loaded('intl'), 'needs the intl extension, as production and CI have');

it('shows a payment overdue', function (): void {
    fakeAirwallex([]);
    billingSubscription($this->organisation, ['airwallex_status' => AirwallexSubscription::STATUS_UNPAID]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)->assertSee('Payment overdue')->assertDontSee('Cancel subscription');
});

it('says so when the invoices can\'t be loaded', function (): void {
    fakeAirwallex(['invoices*' => ['authentication.login.error.bad_key', 500]]);
    billingCustomer($this->organisation);
    billingSubscription($this->organisation);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)->assertSee('Invoices couldn\'t be loaded');
});

it('shows a module ComplyRobin granted as included, with nothing to pay', function (): void {
    fakeAirwallex([]);
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $this->organisation, Module::Credentials, now()->subDay());
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Billing::class)
        ->assertSee('Included in your plan by ComplyRobin')
        ->assertDontSee('Pay monthly');
});

it('is for Owners and Admins of a main organisation only', function (): void {
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Admin), $this->organisation);
    expect(Billing::canAccess())->toBeTrue();

    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation);
    expect(Billing::canAccess())->toBeFalse();

    $branch = Organisation::factory()->create(['parent_id' => $this->organisation->id, 'root_id' => $this->organisation->id]);
    Tenancy::run($branch, fn () => $branch->users()->attach($this->owner));
    openOrganisationAs($this->owner, $branch);
    expect(Billing::canAccess())->toBeFalse();
});
