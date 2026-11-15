---
name: airwallex-billing
description: Work on subscriptions, checkout, invoices and webhooks in app/Billing, the Airwallex integration written in Laravel Cashier's style.
---

# Airwallex billing

## When to use this skill
Any change to subscriptions, plans/prices, checkout, invoices or billing webhooks.

## Usage (reads like Cashier)
```php
return $organisation->newSubscription('isms', $price->airwallex_id)
    ->trialDays(14)
    ->checkout(['success_url' => $success, 'back_url' => $back]);

$organisation->subscribed('bcms');
$organisation->subscription('isms')->cancel();
```
The billable model is the root `Organisation`; entitlements are `Entitlements::has($org, 'isms')` (subscription or manual grant).

## Adding a webhook handler
Add `handle{StudlyEventName}(array $payload)` to `App\Billing\Http\Controllers\WebhookController` (e.g. `invoice.paid` → `handleInvoicePaid`). Signature verification and idempotent storage happen before dispatch; listeners for side effects listen to `WebhookHandled`.

## Testing
Record JSON fixtures from the Airwallex demo environment, use `Http::fake()` plus `Http::preventStrayRequests()`, and cover valid, bad and stale signatures plus duplicate events. The `airwallex` test group hits the real demo environment.
