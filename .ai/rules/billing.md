---
paths:
  - app/Billing/**
  - config/billing.php
---

# Billing (Airwallex, Cashier-style)

## Mirror Laravel Cashier
Class names, method names, return types and docblock wording follow Laravel Cashier exactly, with `stripe` replaced by `airwallex` (`createAsAirwallexCustomer()`, `newSubscription('isms', $price)->trialDays(14)->checkout()`, `subscribed('bcms')`, `onGracePeriod()`). The billable model is the root `Organisation`; the subscription type is the module key.

## One way out
All Airwallex calls go through `AirwallexClient`. Seeders, observers and model events never call Airwallex; prices are pushed explicitly with `airwallex:sync-prices`. Tests use `Http::preventStrayRequests()` with recorded fixtures.

## Webhooks
Verify `x-signature` (HMAC-SHA256 of `x-timestamp` + raw body) with `hash_equals` and a 5-minute tolerance, store the call idempotently by event id, then dispatch `handle{StudlyEventName}` methods between `WebhookReceived` and `WebhookHandled` events.
