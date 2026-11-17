<?php

namespace App\Billing;

use App\Billing\Airwallex\AirwallexObject;
use App\Billing\Exceptions\InvalidInvoice;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * Laravel Cashier's Invoice, wrapping an Airwallex Billing invoice. Airwallex renders invoices itself: `hostedUrl()`
 * views and pays one, `pdfUrl()` downloads it. Both are signed links valid for 35 days, so fetch the invoice fresh
 * rather than storing them. Amounts are in major units.
 *
 * @implements Arrayable<string, mixed>
 */
class Invoice implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * Create a new invoice instance.
     *
     *
     * @throws InvalidInvoice
     */
    public function __construct(
        protected Model $owner,
        protected AirwallexObject $invoice,
    ) {
        if ($owner->getAttribute('airwallex_id') !== $invoice->billing_customer_id) {
            throw InvalidInvoice::invalidOwner($invoice, $owner);
        }
    }

    /**
     * Get a Carbon instance for the invoicing date.
     */
    public function date(DateTimeZone|string|int|null $timezone = null): CarbonInterface
    {
        $carbon = Carbon::parse((string) $this->invoice->created_at)->utc();

        return $timezone ? $carbon->setTimezone($timezone) : $carbon;
    }

    /**
     * Get a Carbon instance for the invoice's due date.
     */
    public function dueDate(DateTimeZone|string|int|null $timezone = null): ?CarbonInterface
    {
        if ($this->invoice->due_at) {
            $carbon = Carbon::parse((string) $this->invoice->due_at)->utc();

            return $timezone ? $carbon->setTimezone($timezone) : $carbon;
        }

        return null;
    }

    /**
     * Get the total amount that was paid (or will be paid).
     */
    public function total(): string
    {
        return Cashier::formatAmount($this->rawTotal(), (string) $this->invoice->currency);
    }

    /**
     * Get the raw total amount that was paid (or will be paid).
     */
    public function rawTotal(): int|float
    {
        return $this->invoice->total_amount ?? 0;
    }

    /**
     * Get the total tax amount.
     */
    public function tax(): string
    {
        return Cashier::formatAmount($this->invoice->total_tax_amount ?? 0, (string) $this->invoice->currency);
    }

    /**
     * Determine if the invoice is paid.
     */
    public function isPaid(): bool
    {
        return $this->invoice->payment_status === 'PAID';
    }

    /**
     * The page where the customer views and pays the invoice.
     */
    public function hostedUrl(): ?string
    {
        return $this->invoice->hosted_url;
    }

    /**
     * The invoice as a PDF, rendered by Airwallex.
     */
    public function pdfUrl(): ?string
    {
        return $this->invoice->pdf_url;
    }

    /**
     * Get the Airwallex model instance.
     */
    public function owner(): Model
    {
        return $this->owner;
    }

    /**
     * Get the Airwallex invoice instance.
     */
    public function asAirwallexInvoice(): AirwallexObject
    {
        return $this->invoice;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->asAirwallexInvoice()->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Dynamically get values from the Airwallex object.
     */
    public function __get(string $key): mixed
    {
        return $this->invoice->{$key};
    }
}
