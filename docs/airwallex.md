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
| `AIRWALLEX_PRICE_PRO_MYR_MONTHLY`, `..._PRO_MYR_YEARLY` | The ringgit prices of Pro (`pri_…`), for organisations in Malaysia | Dashboard → Billing → **Products** → "ComplyRobin Pro" → its MYR prices | No |
| `AIRWALLEX_PRICE_PRO_USD_MONTHLY`, `..._PRO_USD_YEARLY` | The US dollar prices of Pro (`pri_…`), for organisations everywhere else | Same product → its USD prices | No |
| `AIRWALLEX_PRICE_TEAM_MYR_MONTHLY`, `..._TEAM_MYR_YEARLY`, `..._TEAM_USD_MONTHLY`, `..._TEAM_USD_YEARLY` | The same four prices for Team | "ComplyRobin Team" → its prices | No |
| `BILLING_CURRENCY` | `myr` (the default) | | No |
| `BILLING_TRIAL_DAYS` | `14` (the default) | The free trial on an organisation's first subscription to a module. `0` turns trials off. | No |

ComplyRobin never sees a bearer token from you: it logs in with the client ID and API key and keeps the token it gets back until shortly before it expires.

**Which currency?** Each organisation chooses its country at signup (or in Organisation settings). Organisations in Malaysia pay in ringgit and every other country pays in US dollars. The billing page asks for the country before offering a plan.

Leave a price variable empty and the billing page doesn't offer that plan in that currency. An organisation has one subscription to the credentials register, so moving from Pro to Team is done by ComplyRobin staff for now: cancel Pro now in the Airwallex dashboard and grant Team in /admin until the customer subscribes to Team. The BCMS and ISMS prices (`AIRWALLEX_PRICE_BCMS_*`, `AIRWALLEX_PRICE_ISMS_*`) stay empty until those modules are sold.

## 1. API key

1. Open **API keys** in the environment you're setting up.
2. Copy the **Client ID**.
3. Create an **API key**. If Airwallex offers a restricted key, give it access to Billing (customers, products, prices, subscriptions, checkouts, invoices) only. Copy it straight away, because Airwallex shows it once.
4. Put both in the environment (see [Where they go](#where-they-go)).

**ComplyRobin shares Bizexcel's Airwallex account with Cliniclah** (decided 2026-11-17). A key on that account can read every app's webhook secrets, which the API returns, so keep `AIRWALLEX_API_KEY` in Secrets only. ComplyRobin acts only on subscriptions tagged `app: complyrobin` and ignores the rest.

## 2. Products and prices

Make these once per environment, in the dashboard, not from code:

1. **Products** → two new products named brand first, **"ComplyRobin Pro"** and **"ComplyRobin Team"**, each with the metadata `app` = `complyrobin`. The brand name and tag keep them apart from other apps' products on the same account.
2. On each, add a monthly and a yearly recurring price in MYR, and the same two in USD:

   | | MYR monthly | MYR yearly | USD monthly | USD yearly |
   |---|---|---|---|---|
   | Pro | 99 | 990 | 29 | 290 |
   | Team | 349 | 3,490 | 99 | 990 |

   A price can't be changed once someone has paid it, so a new amount means a new price and new ids here.
3. Copy the eight `pri_…` ids into the price variables. Which plan a customer is on comes from the price they pay, so never put a Team price in a Pro variable.

The demo environment has one product from the sandbox test, with placeholder amounts (MYR 49 and 490). Use it as Pro in demo, or rename it "ComplyRobin Pro":

| | Demo id |
|---|---|
| Product "ComplyRobin Team" | `prd_sgpvng7wjho8pct0ieo` |
| Monthly price, MYR | `pri_sgpvng7wjho8pcx79vl` |
| Yearly price, MYR | `pri_sgpvng7wjho8pcxh39u` |

The Team product and every USD price still have to be added in the demo dashboard.

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
3. Back in ComplyRobin, the plan shows as on trial (or active) once Airwallex's webhook has arrived, usually within a minute. Staff see it in /admin → Organisations, in the Subscriptions column.

If choosing a plan says billing isn't available, the client ID or API key is missing or wrong, or `AIRWALLEX_ENV` doesn't match the keys.
