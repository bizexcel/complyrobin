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
`App\Billing\Airwallex\AirwallexSubscription::STATUS_*` stands in for Stripe's `Stripe\Subscription::STATUS_*`. Confirmed in the demo API: `PENDING`, `IN_TRIAL`, `ACTIVE`, `UNPAID`, `CANCELLED`. `PENDING` is Cashier's "incomplete", `IN_TRIAL` its "trialing", `UNPAID` its "past due" (Airwallex has no `PAST_DUE`, and Stripe's separate `unpaid` and `incomplete_expired` have no counterpart), `CANCELLED` its "canceled". So `pastDue()` and `keepPastDueSubscriptionsActive()` work on `UNPAID`. A subscription made with `subscriptions/create` is `ACTIVE` before anything is paid, so subscriptions start only through Billing Checkout (`checkout()`).

## One way out
All Airwallex calls go through `AirwallexClient`. Seeders, observers and model events never call Airwallex. As in Cashier there is no products or prices table: products are made once in the Airwallex dashboard, named brand first ("ComplyRobin Team") and tagged `app: complyrobin` because the account may hold other apps' products, and their price ids come from the environment into `config/billing.php`. Code refers to prices by id, never by name. Tests use `Http::preventStrayRequests()` with fixtures recorded from the demo environment, never hand-written payloads presented as Airwallex's.

## The client
`Cashier::airwallex()` (or `$organisation->airwallex()`) returns `AirwallexClient`, Stripe's `StripeClient` for Airwallex's Billing API: `->billingCustomers`, `->subscriptions`, `->billingCheckouts`, `->invoices`, and read-only `->products` / `->prices`. Each call returns an `AirwallexObject` (fields as properties, like a `StripeObject`) or throws `AirwallexApiError` with Airwallex's `code` and `source`. The client caches the login token until a minute before it expires, logs in again once on a 401, pins `x-api-version`, adds a `request_id` to every POST and retries connection failures and 5xx. Amounts are major units (49 is MYR 49.00).

## What maps to what
- `newSubscription($type, $price)->trialDays(14)->checkout([...])` creates (or reuses) the organisation's billing customer and a SUBSCRIPTION-mode Billing Checkout, with `subscription_data.metadata` carrying `app`, `organisation_id` and `type` so the webhook can match it, and `subscription_data.trial_ends_at` for a trial. Never start a subscription with `subscriptions/create`.
- `cancel()` is `subscriptions/{id}/update` with `cancel_at_period_end`; `cancelNow()` is `subscriptions/{id}/cancel` with `proration_behavior` (`prorate()` → PRORATED, the default; `noProrate()` → NONE). `resume()` is left out: Airwallex ignores `cancel_at_period_end: false`.
- Invoices are rendered by Airwallex: `Invoice::hostedUrl()` and `pdfUrl()` are signed links valid 35 days, so fetch the invoice fresh instead of storing them. There is no local PDF renderer.
- Recurring checkouts take cards, Google Pay and Apple Pay; FPX is refused for subscriptions. No SST is charged for now (Veer, 2026-11-17).

## The billing page
`App\Filament\App\Pages\Billing` (Organisation → Billing) is for a root organisation and `billing.manage` only. It offers each module with a price in `billing.prices.{module}.{monthly|yearly}` (empty means not on sale), calls `StartSubscriptionCheckout` (redirects to Airwallex, coming back with `?checkout=success|cancelled`) and `CancelSubscription` (`cancel()`, at period end), and lists the subscriptions' invoices with Airwallex's view and PDF links. A module granted by staff shows as included. Airwallex errors become a short notice and are reported, never shown. The local subscription row appears only when the webhook arrives. Setup for people: `docs/airwallex.md`.

## Webhooks
`VerifyWebhookSignature` checks `x-signature` (hex HMAC-SHA256 of the `x-timestamp` header, in milliseconds, followed by the raw body) with `hash_equals` and a 5-minute tolerance either way. `WebhookController::handleWebhook()` then, in one transaction, stores the event in `billing_webhook_calls` by its Airwallex event id (`WebhookCall::record()`, "on conflict do nothing"), and only for a new event fires `WebhookReceived`, calls `handle{StudlyEventName}` (`subscription.created` → `handleSubscriptionCreated`) and fires `WebhookHandled`. A repeat is acknowledged and skipped; a failure rolls the stored event back so Airwallex's retry is handled. The app login may only insert into `billing_webhook_calls`; staff read it as the platform. A handler finds the organisation with `getUserByAirwallexId()` and writes inside `Tenancy::run()` it, then clears its entitlements cache (`Entitlements::forget()`).

## Subscription webhooks
`subscription.created` is Cashier's `handleCustomerSubscriptionCreated`; `subscription.in_trial`, `active`, `unpaid` and `modified` share `handleSubscriptionUpdated` (Cashier's `handleCustomerSubscriptionUpdated`); `subscription.cancelled` is `handleCustomerSubscriptionDeleted` (`markAsCanceled()`). Unlike Cashier, a handler reads only the event's `data.object.id` and fetches the subscription and its items from Airwallex, so it works on the latest state whatever order events arrive in. It acts only on a subscription whose metadata has `app: complyrobin` and a module `type`, whose `billing_customer_id` is an organisation's, and whose `organisation_id`, when set, is that organisation's; anything else is acknowledged and skipped, because the Airwallex account is shared with Cliniclah. The event envelope in the tests follows Airwallex's documented shape and still has to be checked against a recorded event from staging.

The first subscription to a module starts with `billing.trial_days` (14) free; subscribing again after cancelling pays at once.

## Entitlements
`Entitlements` counts a module when the root organisation has a manual grant or a `valid()` subscription of that type (Cashier's `subscribed()`): active, on trial or on its grace period. Past due and incomplete subscriptions don't count unless `Cashier::keepPastDueSubscriptionsActive()` / `keepIncompleteSubscriptionsActive()` is set.
