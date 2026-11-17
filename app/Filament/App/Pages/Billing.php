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
 * What the organisation pays for: each module on sale, its subscription and the invoices Airwallex rendered. Only a
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
            ...array_map($this->moduleSection(...), self::modulesOnSale()),
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
     * The modules with at least one price set in config/billing.php.
     *
     * @return list<Module>
     */
    public static function modulesOnSale(): array
    {
        return array_values(array_filter(Module::cases(), fn (Module $module): bool => array_any(
            StartSubscriptionCheckout::INTERVALS,
            fn (string $interval): bool => StartSubscriptionCheckout::price($module, $interval) !== null,
        )));
    }

    private function moduleSection(Module $module): Section
    {
        return Section::make($this->moduleName($module))
            ->key("module-{$module->value}")
            ->schema([Text::make(fn (): string => $this->status($module))])
            ->footer([
                ...array_map(fn (string $interval): Action => $this->subscribeAction($module, $interval), StartSubscriptionCheckout::INTERVALS),
                $this->cancelAction($module),
            ]);
    }

    private function subscribeAction(Module $module, string $interval): Action
    {
        return Action::make("subscribe-{$module->value}-{$interval}")
            ->label($interval === 'monthly' ? 'Pay monthly' : 'Pay yearly')
            ->color($interval === 'monthly' ? 'primary' : 'gray')
            ->visible(fn (): bool => StartSubscriptionCheckout::price($module, $interval) !== null && ! $this->hasModule($module))
            ->action(function (Action $action) use ($module, $interval): void {
                try {
                    $checkout = app(StartSubscriptionCheckout::class)
                        ->handle(Actor::current(), $this->organisation(), $module, $interval, static::getUrl());
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

    private function cancelAction(Module $module): Action
    {
        return Action::make("cancel-{$module->value}")
            ->label('Cancel subscription')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Cancel '.$this->moduleName($module).'?')
            ->modalDescription('It keeps working until the end of the period you\'ve paid for. To use it after that, you subscribe again.')
            ->modalSubmitActionLabel('Cancel subscription')
            ->modalCancelActionLabel('Keep it')
            ->visible(function () use ($module): bool {
                $subscription = $this->subscription($module);

                return $subscription instanceof Subscription && $subscription->valid() && ! $subscription->canceled();
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

    private function status(Module $module): string
    {
        $subscription = $this->subscription($module);

        return match (true) {
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
}
