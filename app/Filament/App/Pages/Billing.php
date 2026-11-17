<?php

namespace App\Filament\App\Pages;

use App\Billing\Actions\CancelSubscription;
use App\Billing\Actions\StartSubscriptionCheckout;
use App\Billing\Exceptions\AirwallexApiError;
use App\Billing\Invoice;
use App\Billing\Subscription;
use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * What the organisation pays for: each plan on sale (the credentials register as Pro or Team, an ISO module as Team), its
 * subscription and the invoices Airwallex rendered. Only a
 * main organisation pays (its sub-organisations share what it buys), and only with billing.manage.
 */
class Billing extends Page implements HasTable
{
    use InteractsWithTable;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    #[\Override]
    protected static ?int $navigationSort = 2;

    #[\Override]
    protected static ?string $title = 'Billing';

    /** Set when Airwallex couldn't be reached for the invoices. */
    public bool $invoicesUnavailable = false;

    /**
     * The invoices, fetched from Airwallex once per request.
     *
     * @var array<string, array{number: string, date: string, total: string, status: string, hosted_url: string, pdf_url: string}>|null
     */
    private ?array $invoices = null;

    public static function canAccess(): bool
    {
        $organisation = Filament::getTenant();
        $user = auth()->user();

        return $organisation instanceof Organisation
            && $organisation->root_id === null
            && $user !== null
            && $user->can(Ability::ManageBilling->value);
    }

    public function mount(): void
    {
        match (request()->query('checkout')) {
            'success' => Notification::make()
                ->title('Thank you. Your payment went through.')
                ->body('Your plan shows here as soon as Airwallex confirms it, usually within a minute.')
                ->success()
                ->send(),
            'cancelled' => Notification::make()->title('Checkout cancelled. You haven\'t been charged.')->send(),
            default => null,
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Your country')
                ->key('country')
                ->visible(fn (): bool => $this->organisation()->country === null)
                ->schema([Text::make('Choose your organisation\'s country before you subscribe. Organisations in Malaysia pay in ringgit, everyone else in US dollars.')])
                ->footer([
                    Action::make('chooseCountry')
                        ->label('Open organisation settings')
                        ->url(fn (): string => OrganisationSettings::getUrl()),
                ]),
            ...array_map(fn (array $offer): Section => $this->planSection(...$offer), self::plansOnSale()),
            Section::make('Invoices')
                ->description('Airwallex sends each invoice by email too.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->invoices ??= $this->invoiceRows())
            ->paginated(false)
            ->emptyStateHeading(fn (): string => $this->invoicesUnavailable ? 'Invoices couldn\'t be loaded' : 'No invoices yet')
            ->emptyStateDescription(fn (): ?string => $this->invoicesUnavailable ? 'Airwallex didn\'t answer. Try again in a minute.' : null)
            ->columns([
                TextColumn::make('number')->label('Invoice'),
                TextColumn::make('date')->date('j M Y'),
                TextColumn::make('total'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Paid' ? 'success' : 'warning'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (array $record): string => $record['hosted_url'], shouldOpenInNewTab: true),
                Action::make('download')
                    ->label('Download PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (array $record): string => $record['pdf_url'], shouldOpenInNewTab: true),
            ]);
    }

    /**
     * The currency the current organisation pays in, decided by its country.
     */
    public static function currency(): string
    {
        $organisation = Filament::getTenant();

        return $organisation instanceof Organisation ? $organisation->preferredCurrency() : (string) config('billing.currency');
    }

    /**
     * The plans with at least one price set in config/billing.php in the current organisation's currency, by module.
     *
     * @return list<array{module: Module, plan: Plan}>
     */
    public static function plansOnSale(): array
    {
        $offers = [];

        foreach (Module::cases() as $module) {
            foreach (array_keys((array) config("billing.prices.{$module->value}", [])) as $planKey) {
                $plan = Plan::tryFrom((string) $planKey);

                if ($plan instanceof Plan && array_any(
                    StartSubscriptionCheckout::INTERVALS,
                    fn (string $interval): bool => StartSubscriptionCheckout::price($module, $plan, $interval, self::currency()) !== null,
                )) {
                    $offers[] = ['module' => $module, 'plan' => $plan];
                }
            }
        }

        return $offers;
    }

    private function planSection(Module $module, Plan $plan): Section
    {
        return Section::make($this->offerName($module, $plan))
            ->key("plan-{$module->value}-{$plan->value}")
            ->description($this->offerDescription($module, $plan))
            ->schema([Text::make(fn (): string => $this->status($module, $plan))])
            ->footer([
                ...array_map(fn (string $interval): Action => $this->subscribeAction($module, $plan, $interval), StartSubscriptionCheckout::INTERVALS),
                $this->cancelAction($module, $plan),
            ]);
    }

    private function subscribeAction(Module $module, Plan $plan, string $interval): Action
    {
        return Action::make("subscribe-{$module->value}-{$plan->value}-{$interval}")
            ->label($interval === 'monthly' ? 'Pay monthly' : 'Pay yearly')
            ->color($interval === 'monthly' ? 'primary' : 'gray')
            ->visible(fn (): bool => $this->organisation()->country !== null
                && StartSubscriptionCheckout::price($module, $plan, $interval, self::currency()) !== null
                && ! $this->hasModule($module))
            ->action(function (Action $action) use ($module, $plan, $interval): void {
                try {
                    $checkout = app(StartSubscriptionCheckout::class)
                        ->handle(Actor::current(), $this->organisation(), $module, $plan, $interval, static::getUrl());
                } catch (ValidationException $exception) {
                    Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                } catch (AirwallexApiError|HttpClientException $exception) {
                    report($exception);
                    Notification::make()->title('Payments aren\'t available right now. Try again in a few minutes.')->danger()->send();
                    $action->halt();

                    return;
                }

                $this->redirect((string) $checkout->asAirwallexCheckoutSession()->url);
            });
    }

    private function cancelAction(Module $module, Plan $plan): Action
    {
        return Action::make("cancel-{$module->value}-{$plan->value}")
            ->label('Cancel subscription')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Cancel '.$this->offerName($module, $plan).'?')
            ->modalDescription('It keeps working until the end of the period you\'ve paid for. To use it after that, you subscribe again.')
            ->modalSubmitActionLabel('Cancel subscription')
            ->modalCancelActionLabel('Keep it')
            ->visible(function () use ($module, $plan): bool {
                $subscription = $this->subscription($module);

                return $subscription instanceof Subscription && $subscription->valid() && ! $subscription->canceled()
                    && Entitlements::planOf($subscription) === $plan;
            })
            ->action(function (Action $action) use ($module): void {
                try {
                    $subscription = app(CancelSubscription::class)->handle(Actor::current(), $this->organisation(), $module);
                } catch (ValidationException $exception) {
                    Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                } catch (AirwallexApiError|HttpClientException $exception) {
                    report($exception);
                    Notification::make()->title('Airwallex didn\'t answer, so nothing was cancelled. Try again in a few minutes.')->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Subscription cancelled. It works until '.$subscription->ends_at?->format('j M Y').'.')->success()->send();
            });
    }

    private function status(Module $module, Plan $plan): string
    {
        $subscription = $this->subscription($module);
        $subscribedPlan = $subscription instanceof Subscription && $subscription->valid() ? Entitlements::planOf($subscription) : null;

        return match (true) {
            $subscribedPlan instanceof Plan && $subscribedPlan !== $plan => $subscribedPlan->includes($plan)
                ? "You're on {$subscribedPlan->label()}, which includes everything in {$plan->label()}."
                : "You're on {$subscribedPlan->label()}. To move to {$plan->label()}, contact us and we'll switch your plan with no gap.",
            $subscription instanceof Subscription && $subscription->onGracePeriod() => 'Cancelled. It works until '.$subscription->ends_at?->format('j M Y').'.',
            $subscription instanceof Subscription && $subscription->onTrial() => 'On a free trial until '.$subscription->trial_ends_at?->format('j M Y').'.',
            $subscription instanceof Subscription && $subscription->pastDue() => 'Payment overdue. Pay the open invoice below to keep using it.',
            $subscription instanceof Subscription && $subscription->active() => 'Active. It renews automatically.',
            $this->hasModule($module) => 'Included in your plan by ComplyRobin. Nothing to pay.',
            default => $this->organisation()->subscriptions()->where('type', $module->value)->exists()
                ? 'Not subscribed. Choose how you\'d like to pay. Cards, Google Pay and Apple Pay are accepted.'
                : 'Not subscribed. Try it free for '.config('billing.trial_days').' days, then pay monthly or yearly by card, Google Pay or Apple Pay.',
        };
    }

    /**
     * Whether the organisation can use the module, from a subscription or from a grant by ComplyRobin staff.
     */
    private function hasModule(Module $module): bool
    {
        return app(Entitlements::class)->has($module) || ($this->subscription($module)?->valid() ?? false);
    }

    private function subscription(Module $module): ?Subscription
    {
        return $this->organisation()->subscriptions()->where('type', $module->value)->latest()->first();
    }

    /**
     * @return array<string, array{number: string, date: string, total: string, status: string, hosted_url: string, pdf_url: string}>
     */
    private function invoiceRows(): array
    {
        $organisation = $this->organisation();

        if (! $organisation->hasAirwallexId()) {
            return [];
        }

        try {
            return $organisation->subscriptions()->whereNotNull('airwallex_id')->get()
                ->flatMap(fn (Subscription $subscription): Collection => $subscription->invoices())
                ->sortByDesc(fn (Invoice $invoice): int => $invoice->date()->getTimestamp())
                ->mapWithKeys(fn (Invoice $invoice): array => [(string) $invoice->asAirwallexInvoice()->id => [
                    'number' => (string) $invoice->asAirwallexInvoice()->number,
                    'date' => $invoice->date()->toDateString(),
                    'total' => $invoice->total(),
                    'status' => $invoice->isPaid() ? 'Paid' : 'Unpaid',
                    'hosted_url' => (string) $invoice->hostedUrl(),
                    'pdf_url' => (string) $invoice->pdfUrl(),
                ]])
                ->all();
        } catch (AirwallexApiError|HttpClientException $exception) {
            report($exception);
            $this->invoicesUnavailable = true;

            return [];
        }
    }

    private function organisation(): Organisation
    {
        $organisation = Filament::getTenant();
        abort_unless($organisation instanceof Organisation, 404);

        return $organisation;
    }

    private function moduleName(Module $module): string
    {
        return match ($module) {
            Module::Credentials => 'Credentials register',
            Module::Bcms => 'Business continuity (ISO 22301)',
            Module::Isms => 'Information security (ISO 27001)',
        };
    }

    /**
     * The credentials register is sold by plan name; an ISO module by its own name.
     */
    private function offerName(Module $module, Plan $plan): string
    {
        return $module === Module::Credentials ? $plan->label() : $this->moduleName($module);
    }

    private function offerDescription(Module $module, Plan $plan): ?string
    {
        return match (true) {
            $module === Module::Credentials && $plan === Plan::Pro => 'Up to 5 members and 100 credentials, with reminders in Slack and Microsoft Teams and spreadsheet import.',
            $module === Module::Credentials && $plan === Plan::Team => '10 members and unlimited credentials. Adds WhatsApp reminders, escalation, evidence reports, audit export and automatic rotation.',
            $module !== Module::Credentials => 'Includes the credentials register on Team.',
            default => null,
        };
    }
}
