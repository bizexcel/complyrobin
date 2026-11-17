---
paths:
  - app/Billing/**
  - config/billing.php
---

# Billing (Airwallex, Cashier-style)

## Mirror Laravel Cashier, word for word
Copy from Laravel Cashier's own source (`laravel/cashier-stripe`), then replace `stripe` with `airwallex` and `Stripe` with `Airwallex`: class names, method names, argument names and order, return types, docblock wording ("Determine if the subscription is within its grace period after cancellation."), exception messages, factory states and column names (`stripe_id` → `airwallex_id`, `stripe_status` → `airwallex_status`). Don't write a Cashier method from memory and don't rename one to suit Airwallex. The billable model is the root `Organisation`; the subscription type is the module key (`credentials`, `bcms`, `isms`). A Cashier feature Airwallex can't support is left out, never imitated; leave a method out until its Airwallex call is built and recorded.

Where this app's rules win over Cashier's code (the words stay Cashier's):
- **Tenancy.** `subscriptions` and `subscription_items` are tenant tables (`Schema::tenantTable()`), so Cashier's customer foreign key is `organisation_id`, and both models use `BelongsToOrganisation`, `HasUuids` and `LogsTenantActivity` with `#[Fillable]` instead of `$guarded = []`. Factories don't set the owner: create them inside `Tenancy::run()`. Sub-organisations read their root's rows through a `*_root_rows` policy.
- **Types.** Methods get the parameter and return types Larastan needs; docblocks keep Cashier's sentences.
- **Webhook secret.** Cashier skips signature checks when no secret is set; here every call is verified, and with no secret every call is refused.
- **Configuration statics.** `Cashier::$deactivatePastDue` and the other statics are application configuration, set once while the app boots (a service provider), never per request or job.
- **Names.** The config file is `config/billing.php` (`billing.*`); the route keeps Cashier's name, `cashier.webhook`, at `/airwallex/webhook`.

## Airwallex statuses
`App\Billing\Airwallex\AirwallexSubscription::STATUS_*` stands in for Stripe's `Stripe\Subscription::STATUS_*`. `PENDING` is Cashier's "incomplete", `IN_TRIAL` its "trialing", `CANCELLED` its "canceled". The list is to be confirmed against recorded demo payloads; Stripe's `incomplete_expired` has no Airwallex counterpart and is left out of `active()`.

## One way out
All Airwallex calls go through `AirwallexClient`. Seeders, observers and model events never call Airwallex; prices are pushed explicitly with `airwallex:sync-prices`. Tests use `Http::preventStrayRequests()` with fixtures recorded from the demo environment, never hand-written payloads presented as Airwallex's.

## Webhooks
`VerifyWebhookSignature` checks `x-signature` (hex HMAC-SHA256 of the `x-timestamp` header, in milliseconds, followed by the raw body) with `hash_equals` and a 5-minute tolerance either way. `WebhookController::handleWebhook()` then, in one transaction, stores the event in `billing_webhook_calls` by its Airwallex event id (`WebhookCall::record()`, "on conflict do nothing"), and only for a new event fires `WebhookReceived`, calls `handle{StudlyEventName}` (`subscription.created` → `handleSubscriptionCreated`) and fires `WebhookHandled`. A repeat is acknowledged and skipped; a failure rolls the stored event back so Airwallex's retry is handled. The app login may only insert into `billing_webhook_calls`; staff read it as the platform. A handler finds the organisation with `getUserByAirwallexId()` and writes inside `Tenancy::run()` it, then clears its entitlements cache (`Entitlements::forget()`).

## Entitlements
`Entitlements` counts a module when the root organisation has a manual grant or a `valid()` subscription of that type (Cashier's `subscribed()`): active, on trial or on its grace period. Past due and incomplete subscriptions don't count unless `Cashier::keepPastDueSubscriptionsActive()` / `keepIncompleteSubscriptionsActive()` is set.
