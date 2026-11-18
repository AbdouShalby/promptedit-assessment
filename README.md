# PromptEdit image generator

A small Laravel + Vue + Inertia app where signed-in users generate images through the
Pixelforge API and are charged the exact provider cost plus a 25% markup.

Author: Abdelrhman Shalby

Guiding rule: a small app that records every cost exactly right beats a bigger one that
does not. Every choice below prefers small, explainable and correct over clever.

> **Status.** Everything in this repository is built and covered by automated tests
> (187 tests, run on Postgres). On 2026-11-18 it was run locally against the live
> Pixelforge API (local key, webhooks through a tunnel): 24 generations, every failure
> keyword, duplicate and late webhooks, a stopped worker and an unreachable webhook
> endpoint. Provider cost in the app, `1.511880000`, equals `GET /v1/usage` for that
> key.
>
> **Live: <https://pixel.sroforge.tech>** (a shared VPS behind Cloudflare). On 2026-11-18
> a reduced matrix was run there with the production key: every keyword once, 5 at once,
> reference images. 11 rows: 8 succeeded, 3 failed (`[fail]`, `[blocked]`,
> `[overloaded]`), nothing in flight. Provider cost in the app, `0.893562500`, equals
> `GET /v1/usage` for the production key; users were charged `1.116953125`, exactly 1.25
> times that. That run drove the app's real routes on the server as the demo user; the
> pages were **not** clicked through in a browser on production (see Known gaps).
> Both runs are recorded in [docs/verify-live.md](docs/verify-live.md); the state of
> every requirement is in [docs/checklist.md](docs/checklist.md).

## What it does

- **Generate**: prompt, size, quality, up to 4 reference image URLs. The price range is
  shown before you submit.
- **History**: your own generations with live status (Queued, Processing, Succeeded,
  Failed), the estimate, the actual cost and the image.
- **Webhooks**: verified, deduplicated, safe against duplicates and reordering, with a
  polling fallback.
- **Stretch**: an admin cost report checked against Pixelforge's own usage figures, and
  prepaid credit balances with a fake "Buy credits" button.

## How it works

```
Browser            App (web)            Queue worker              Pixelforge
   |  POST /generations  |                    |                        |
   |-------------------->| insert row (pending, uuid)                  |
   |                     | reserve credit, queue SubmitGeneration      |
   |<--- redirect -------|                    |                        |
   |                     |                    | POST /v1/generations   |
   |                     |                    | Idempotency-Key = uuid |
   |                     |                    | metadata.local_id=uuid |
   |                     |                    |----------------------->|
   |                     |                    |<------ 202 queued -----|
   |                     |                    | ApplyProviderSnapshot  |
   |                     |<------------- webhook (signed) -------------|
   |                     | verify, store event id, queue job, 200      |
   |                     |                    | ApplyProviderSnapshot  |
   |                     |                    |                        |
   |                     |    every 15 s: reconcile -> GET /v1/generations/{id}
   |                     |                    | ApplyProviderSnapshot  |
   |  poll every 2 s --->| (our own database) |                        |
```

1. **Money is integer nanodollars** (1 USD = 1,000,000,000) in `BIGINT` columns. No
   float touches money, and the browser never does money arithmetic: it only shows
   strings formatted by the server.
2. **One function owns every status and cost change**: `ApplyProviderSnapshot`. The
   submit response, every webhook and every poll all carry the same thing (a full
   Pixelforge generation object), so all three call that one function. It locks the row,
   only moves forward, and writes the cost in the same transaction as the move to
   `succeeded`.
3. **The local row exists before Pixelforge is called.** Its UUID is the
   `Idempotency-Key` and is also sent as `metadata.local_id`, so a webhook that arrives
   before our own POST returns can still find its row.
4. **Submitting is a queued job.** It waits for a free slot (3 concurrent per account),
   respects `Retry-After`, backs off, and gives up with a clear message.
5. **Webhooks**: HMAC verified on the raw body, event id inserted under a unique index,
   work done in a queued job, 200 returned at once.
6. **Polling fallback**: a scheduled command re-fetches generations that have had no
   news for 20 seconds and feeds the result to the same function as point 2.
7. **UI**: one page, refreshed by Inertia polling while anything is in flight.

Where to look in the code:

| Concern | File |
|---|---|
| Parse and format money | `app/Support/Nano.php` |
| Tokens to money, markup | `app/Billing/CostCalculator.php` |
| Price range before submit | `app/Billing/Estimator.php` |
| Status and cost changes | `app/Generations/ApplyProviderSnapshot.php` |
| Credit ledger | `app/Billing/CreditLedger.php` |
| Pixelforge HTTP calls | `app/Pixelforge/PixelforgeClient.php` |
| Webhook signature | `app/Pixelforge/WebhookSignature.php` |
| Submit / retry rules | `app/Jobs/SubmitGeneration.php` |
| Webhook endpoint and job | `app/Http/Controllers/PixelforgeWebhookController.php`, `app/Jobs/ProcessWebhookEvent.php` |
| Polling fallback | `app/Console/Commands/ReconcileGenerations.php`, `app/Jobs/SyncGeneration.php` |
| Pages | `resources/js/pages/generations/Index.vue`, `resources/js/pages/admin/Report.vue` |

## Money

- Pixelforge sends money as decimal strings with up to 9 decimals, so every amount is a
  whole number of nanodollars. `Nano::fromDecimal()` parses with string operations only.
- The API never returns a price. `cost = sum(tokens x rate) / 1,000,000` with rates in
  USD per 1M tokens. The division must be exact; if it is not, the calculator **throws**
  instead of rounding, because we must never silently disagree with the provider.
- The actual cost uses the **rates on the generation object** (fixed at creation), not
  today's price list. The price list is used for estimates only and is read from
  `GET /v1/pricing` (cached 5 minutes, with a last-known-good copy). Nothing is hardcoded.
- **Markup**: `charge = ceil(cost x 1.25)` in nanodollars. The user pays exactly 25% on
  top, to 9 decimals. Only a fraction of one nanodollar can be left over and it is
  rounded up, so profit is never negative. With today's rates every cost is a multiple of
  4 nanodollars, so the rule never fires; it exists so behaviour is defined if rates change.
- I do not round each generation to cents: a $0.004 image rounded up to $0.01 would be a
  150% markup, not 25%.
- The markup rate is stored on each generation, so changing the setting later does not
  rewrite history.
- **Worked example from the Pixelforge docs** (high, 1536x1024, 400-character prompt, 2
  reference images): 115 text + 1530 image + 6450 output + 8000 reasoning tokens =
  `0.373875000`. This is a unit test. The user is charged `0.467343750`.
- **Display**: 4 decimals, with the exact 9-decimal value in the tooltip. Costs are
  rounded up for display and balances down, so the screen never understates a cost or
  overstates credit. Display rounding never touches stored values. The admin report
  shows exact 9-decimal values.

## Estimate

Two token counts are only known when the image is finished: text tokens (within 15% of
`ceil(characters / 4)`) and reasoning tokens (a wide range per quality). Image and output
tokens are exact. So the form shows a **range**, "Estimated cost $min to $max", computed
on the server so there is one tested implementation. Characters are Unicode code points
of the exact string that is stored and sent.

If an actual cost ever falls outside the stored range, the user is still charged the
actual cost (the books must stay true) and a warning is logged.

## State machine

| Status | Rank | Meaning |
|---|---|---|
| `pending` | 0 | Local only: not yet accepted by Pixelforge. Shown as "Queued, waiting for a free slot" |
| `queued` | 1 | Accepted by Pixelforge |
| `processing` | 2 | |
| `succeeded` / `failed` | 3 | Final |

A snapshot is applied only if its rank is higher than the row's current rank. So:

- the same event twice: the second is a no-op;
- a late `processing` after `succeeded`: ignored;
- a webhook before our POST returned: the row jumps from `pending` straight to wherever
  Pixelforge says it is;
- a cost is written exactly once, because the move into `succeeded` happens once.

Three independent layers stop a cost from being recorded twice: the unique webhook event
id, the forward-only move under a row lock, and a unique `(generation, type)` index on
the credit ledger.

**One deliberate exception.** If we give up on a submission after timeouts, we cannot
know whether Pixelforge created it. The row is marked failed with the message "We could
not confirm that Pixelforge received this request", and its cost stays unknown (not
zero). If Pixelforge did create it, its webhook still claims the row through
`metadata.local_id` and the real cost is recorded. The alternative would be a generation
Pixelforge bills that our books never show, which would break the usage comparison.

The app says "you were not charged" only when Pixelforge itself reported the failure, or
rejected the request before creating anything.

## Webhooks

- Route: `POST /api/webhooks/pixelforge` (no session, no CSRF).
- Signature: `Pixelforge-Signature: t=<unix>,v1=<hex>`, HMAC-SHA256 of `<t>.<raw body>`,
  compared in constant time, on the raw bytes before JSON decoding. Timestamps more than
  300 seconds from now are rejected.
- The controller only verifies, inserts the event (unique `event_id`) and queues a job,
  in one database transaction, then returns 200. A redelivery inserts nothing and is
  answered 200. `ping` and unknown event types are stored and acknowledged.
- Delivery is not guaranteed, so `generations:reconcile` runs every 15 seconds: accepted
  generations with no news for 20 seconds are fetched with `GET /v1/generations/{id}`,
  and pending rows whose job was lost are re-queued. At most 3 generations are in flight
  at Pixelforge, so this costs about 12 of the 60 requests per minute.

## Limits and failures

| What Pixelforge says | What the app does | What the user sees |
|---|---|---|
| All 3 slots are ours already | Waits locally, **without calling the API** | Queued, "Waiting for a free slot" |
| 429 `concurrency_limit_exceeded` / `rate_limit_exceeded` | Waits `Retry-After` or a growing backoff (2, 4, 8, 16, 30 s), at most 6 attempts | Queued, then "too busy, not charged" |
| 503 `overloaded` | Same key, same backoff, at most 5 attempts | Queued, then "too busy, not charged" |
| Timeout, connection error, other 5xx | Outcome unknown: retries with the same Idempotency-Key, at most 5 attempts | Queued, then "could not confirm" (no promise about the charge) |
| 400 `content_policy_violation` | Fails at once | "Rejected by the content policy, not charged" |
| 429 `daily_limit_exceeded` | Fails at once, no retry | "Capacity used up, try tomorrow (UTC), not charged" |
| 422 / 401 / 409 | Fails at once, logged as an error (our bug or configuration) | "Problem on our side" |
| Generation `failed` | Cost 0, reserve returned | "Could not generate this image. You were not charged." |
| Waiting longer than 10 minutes | Gives up | "too busy, not charged" |

The app branches on `error.code`, never on message text.

## Stretch 1: admin cost report (`/admin/report`)

Per UTC day and per user: charged to users, provider cost, profit. A generation is
reported on the UTC day it **completed**, stored once as `billing_date` from Pixelforge's
own `completed_at`, because that is the day Pixelforge bills it. Each day shows
Pixelforge's figure from `GET /v1/usage` with a Match or Mismatch badge, and a day that
exists only on Pixelforge's side is shown too. A difference is displayed, never hidden.

This only works if **every generation on the API key was created through this app**. So:
one key per environment, never use the production key from curl or locally, and never
reset the production database after its first generation.

## Stretch 2: balances

- New users start at $0 and see a "Buy credits" button (fake: adds $5, no payment). The
  seeded demo user starts with $10.
- Submitting **reserves the maximum estimate**; at completion the reserve is released and
  the actual cost is charged. Charging only at completion would let a user with $0.10
  start three $0.40 generations at once. The balance check and the reserve happen under
  a lock on the user row.
- The ledger (`credit_entries`) is append-only. A unique `(generation_id, type)` index
  means the database itself refuses a second charge for the same generation.
  `users.balance_nano` always equals the sum of the user's entries (asserted in tests).
- This is stricter than the brief asks: generating is blocked on the maximum estimate,
  not the final cost. The UI says so.
- Known corner: a submission we gave up on and released, later claimed by a late
  snapshot (see above), is still charged, which can take a balance slightly below zero.
  It needs a provider outage during a create call. Accepted and tested.

## Decisions and trade-offs

| Decision | Choice | Why |
|---|---|---|
| Money type | Integer nanodollars | Exactly the API's precision; integer maths is exact and needs no library |
| At the concurrency limit | Queue locally, give up after 10 minutes | The limit is account-wide, so it reflects other users' traffic. Waiting beats an error the user can only answer by clicking again |
| Live status | Inertia polling every 2 s, only while something is in flight | Generations take 5 to 90 seconds; no websocket server to deploy or explain |
| Images | Not downloaded; "Image expired" after 24 hours | Allowed by the brief; avoids object storage. A queued download would be the next step |
| Queue / cache / session | `database` driver | No Redis. The webhook event row and its job commit in one transaction |
| Double submit | The browser creates a UUID per form; it is the row id and the Idempotency-Key | Idempotent from the browser to Pixelforge with one key |
| Sizes and qualities | Whatever `GET /v1/pricing` lists | A new tier needs no code change |
| Per-user limit | 5 generations in flight | One user cannot fill the shared queue |
| Registration | Open, plus seeded demo and admin accounts | Reviewers can use either. Email verification is not required to generate |
| Database | Postgres, also for tests | Unique indexes and row locks are what protect the money |
| Hosting | Own VPS: Nginx, PHP-FPM, Postgres, Supervisor, cron | Always on, so the worker and the 15-second reconciler never sleep |

## What I would do next

- Persist images to object storage in a queued job after success.
- Websockets (Reverb) instead of polling.
- First-in-first-out fairness between users while waiting for a slot.
- A backfill command from `GET /v1/generations` to find generations created outside the app.
- An alert when the report shows a mismatch, and when an actual cost falls outside its estimate.
- For an unconfirmed submission, look the generation up in `GET /v1/generations` by
  `metadata.local_id` instead of relying only on its webhook. Today, if that one webhook
  is also lost, the cost is not recorded and the report shows a Mismatch for that day
  (visible, but not repaired automatically).
- Park a generation whose snapshot cannot be priced (unknown token type, inexact cost)
  for manual review. Today it stays Processing and keeps one of the 3 slots; the app
  refuses to guess a cost.

## Running locally

Requirements: PHP 8.3+ with `pdo_pgsql`, Composer, Node 22+, Docker (for Postgres).

```bash
docker compose up -d                 # Postgres 16 on 127.0.0.1:55432
cp .env.example .env                 # then set DB_PASSWORD=promptedit and the values below
composer install
npm install
php artisan key:generate
php artisan migrate --seed
composer run dev                     # web server, queue worker, scheduler and Vite together
```

Values to set in `.env`:

| Variable | What |
|---|---|
| `PIXELFORGE_API_KEY` | A key created for **this environment only** |
| `PIXELFORGE_WEBHOOK_SECRET` | That key's signing secret (`whsec_...`) |
| `DEMO_USER_EMAIL`, `DEMO_USER_PASSWORD` | Demo account created by the seeder ($10 of credit) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Admin account created by the seeder (sees `/admin/report`) |
| `APP_URL` | The URL you open in the browser |

Webhooks need a public URL. Start a tunnel (Cloudflare Tunnel, ngrok or Expose) to the
local web server and set the key's webhook URL in the Pixelforge dashboard to
`https://<tunnel-host>/api/webhooks/pixelforge`. Send a `ping` from the dashboard and
check that it is answered with 200. Without a tunnel the app still works: the reconciler
catches up by polling.

Deployment on a VPS: [docs/deploy-vps.md](docs/deploy-vps.md).

## Tests

```bash
docker compose up -d
docker exec promptedit-db-1 psql -U promptedit -d promptedit -c "CREATE DATABASE promptedit_test OWNER promptedit;"   # once
php artisan test
```

Tests run on Postgres and never call Pixelforge: every HTTP call is faked, and a stray
request fails the test. `tests/Fixtures/pricing.json` is a real capture of
`GET /v1/pricing`, and `tests/Fixtures/live` holds webhook events and a `GET /v1/usage`
response captured from the real API (image URL signature removed). The other payloads
are built from the API documentation and have the same shape.

Other checks: `composer lint:check` (Pint), `composer types:check` (PHPStan),
`npm run check` (lint and format), `npm run types:check` (vue-tsc), `npm run build`.

## Trying the failure paths by hand

Pixelforge reacts to keywords in the prompt:

| Prompt contains | Expect |
|---|---|
| `[succeed]` | Queued, Processing, Succeeded; actual cost inside the estimate; image shown |
| `[fail]` | Ends Failed, "Not charged", reserve returned to your credit |
| `[slow]` | Stays Processing for 60 to 90 seconds, then finishes |
| `[duplicate]` | Every webhook arrives twice; both answered 200; cost recorded once (one row per event id in `webhook_events`) |
| `[overloaded]` | Stays Queued while retrying with backoff, then Failed "too busy, not charged" after 5 attempts |
| `[blocked]` | Failed with the content policy message, not charged |

More checks: submit 5 `[slow] [succeed]` generations quickly (3 run, 2 wait, all finish);
resend a delivery from the Pixelforge dashboard (200, nothing changes); stop the queue
worker, generate, start it again (the row catches up); point the webhook URL at a dead
path (the reconciler still settles the row); post a webhook with a wrong signature (401,
nothing stored). The full matrix, with the results of the local run and of the
production run, is in [docs/verify-live.md](docs/verify-live.md).

## Known gaps

- On production the matrix was reduced and was driven through the app's routes on the
  server, not through a browser: signing in, the live status updates on screen, the Buy
  credits button and the phone layout were seen in a browser only locally. Dashboard
  resends, a stopped worker and an unreachable webhook endpoint were also tested only
  locally.
- Image links expire 24 hours after a generation finishes; after that the card shows the
  "expired" placeholder. The images of the production run expire on 2026-11-19 around
  19:00 UTC.
- An unconfirmed submission is repaired only by its webhook, not by polling.
- A snapshot that cannot be priced stays Processing and keeps one of the 3 slots.
- A late-claimed generation is charged without a new hold, so a balance can go slightly
  below zero.
- No first-in-first-out fairness between users waiting for a slot.
- While a submission is retried after a 503, its card says "Waiting for a free slot".
- The per-minute and daily limits, a create call that times out, and a webhook that
  beats the create response were never produced by the real API in the local run; they
  are covered by automated tests only.
- Phone width was checked in a 390 px frame, not on a real phone.

## How AI tools were used

The brief allows AI tools. The requirements checklist and the design were written
before any code. The code was then written with an AI coding assistant (Claude Code)
against that design, in small commits, each with its tests.
