# Setting up Airwallex billing

This guide lists every Airwallex setting ComplyRobin reads, where each value comes from, and where to put it. Airwallex's API was checked against the demo environment on 2026-11-17. Its dashboard menus may move, so if a screen looks different, search the dashboard for the name in **bold**.

Airwallex has two environments with separate keys, products and webhooks:

| | Demo (testing) | Production (real money) |
|---|---|---|
| Dashboard | `demo.airwallex.com` | `www.airwallex.com/app` |
| API ComplyRobin calls | `api-demo.airwallex.com` | `api.airwallex.com` |
| `AIRWALLEX_ENV` | `demo` | `production` |

Keys from one environment don't work in the other. Use demo for local, preview and staging, and production only on the `production` Cloud environment.

## The variables

| Variable | What it is | Where to find it | Secret? |
|---|---|---|---|
| `AIRWALLEX_ENV` | `demo` or `production` | You choose it, as in the table above | No |
| `AIRWALLEX_CLIENT_ID` | Identifies your account to the API | Dashboard → Settings → Developer → **API keys** → Client ID | No, but keep it with the key |
| `AIRWALLEX_API_KEY` | Lets ComplyRobin log in to the API | Same page → API key (shown once when you create it) | **Yes** |
| `AIRWALLEX_WEBHOOK_SECRET` | Proves a webhook really came from Airwallex | Dashboard → Settings → Developer → **Webhooks** → your endpoint → secret | **Yes** |
| `AIRWALLEX_PRICE_CREDENTIALS_MONTHLY` | The monthly price of the credentials register (`pri_…`) | Dashboard → Billing → **Products** → "ComplyRobin Team" → monthly price | No |
| `AIRWALLEX_PRICE_CREDENTIALS_YEARLY` | The yearly price (`pri_…`) | Same product → yearly price | No |
| `BILLING_CURRENCY` | `myr` (the default) | | No |

ComplyRobin never sees a bearer token from you: it logs in with the client ID and API key and keeps the token it gets back until shortly before it expires.

Leave a price variable empty and the billing page doesn't offer that plan. The BCMS and ISMS prices (`AIRWALLEX_PRICE_BCMS_*`, `AIRWALLEX_PRICE_ISMS_*`) stay empty until those modules are sold.

## 1. API key

1. Open **API keys** in the environment you're setting up.
2. Copy the **Client ID**.
3. Create an **API key**. If Airwallex offers a restricted key, give it access to Billing (customers, products, prices, subscriptions, checkouts, invoices) only. Copy it straight away, because Airwallex shows it once.
4. Put both in the environment (see [Where they go](#where-they-go)).

**Use an Airwallex account that only ComplyRobin uses.** A key on a shared account can read the other apps' webhook secrets (the demo API returns them), so a leak of ComplyRobin's key would expose those apps too.

## 2. Products and prices

Make these once per environment, in the dashboard, not from code:

1. **Products** → new product named brand first, **"ComplyRobin Team"**, with the metadata `app` = `complyrobin`. The brand name and tag keep it apart from other apps' products on the same account.
2. Add a monthly and a yearly recurring price in MYR. A price can't be changed once someone has paid it, so a new amount means a new price and new ids here.
3. Copy the two `pri_…` ids into the price variables.

The demo environment already has these, made in the sandbox test. Their amounts (MYR 49 and 490) are placeholders:

| | Demo id |
|---|---|
| Product "ComplyRobin Team" | `prd_sgpvng7wjho8pct0ieo` |
| Monthly price | `pri_sgpvng7wjho8pcx79vl` |
| Yearly price | `pri_sgpvng7wjho8pcxh39u` |

## 3. Webhook

Airwallex tells ComplyRobin when a checkout completes, a payment fails or a subscription ends, by calling its webhook. That needs a public address, so it doesn't work on your own computer.

1. **Webhooks** → add an endpoint: `https://<your app's address>/airwallex/webhook`, for example `https://app.complyrobin.com/airwallex/webhook`. Staging gets its own endpoint, on the demo account.
2. Choose the events: `billing_checkout.completed`, `subscription.created`, `subscription.in_trial`, `subscription.active`, `subscription.unpaid`, `subscription.modified`, `subscription.cancelled`, and `invoice.payment.paid`.
3. Copy the endpoint's secret into `AIRWALLEX_WEBHOOK_SECRET`.

ComplyRobin refuses every webhook while the secret is empty.

## Where they go

- **Your computer:** in `.env` (copy the names from `.env.example`). Use demo keys only.
- **Laravel Cloud:** the plain values in the environment's General Settings, and `AIRWALLEX_API_KEY` and `AIRWALLEX_WEBHOOK_SECRET` in **Secrets**, linked to the environment. Redeploy afterwards. See [Deploying to Laravel Cloud](laravel-cloud.md).
- **Claude's cloud sessions:** in the "complyrobin" environment's variables, demo keys only. That environment is the one allowed to reach `api-demo.airwallex.com`.

## Checking it works

1. Open **Organisation → Billing** in the app as the organisation's Owner. The plans appear once their prices are set.
2. Choose a plan. You should land on Airwallex's checkout page. In demo, pay with one of Airwallex's test cards.
3. Back in ComplyRobin, the plan shows as active once Airwallex's webhook has arrived, usually within a minute.

If choosing a plan says billing isn't available, the client ID or API key is missing or wrong, or `AIRWALLEX_ENV` doesn't match the keys.
