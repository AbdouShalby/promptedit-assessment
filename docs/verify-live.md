# What was verified against the live Pixelforge API

Author: Abdelrhman Shalby

The app was first built without an API key, from the API documentation, with faked HTTP
responses. On 2026-11-18 (17:44 to 18:13 UTC) it was run locally against the real API
with the `local` key, through a Cloudflare quick tunnel for webhooks. This file records
what was checked, how, and what is still open.

On the same day the app was deployed to <https://pixel.sroforge.tech> and a reduced
matrix was run there with the production key (18:53 to 19:02 UTC). That run is section F.

Legend: **VERIFIED** = seen working against the real API. **NOT EXERCISED** = the real
API never produced this case during the run, so it is covered by automated tests only.
**PENDING** = still open.

Totals of the local run: 26 rows in the app; 24 generations created at Pixelforge
(23 succeeded, 1 failed); 2 rejected before anything was created (`[blocked]`,
`[overloaded]`); 51 webhook events stored (50 from Pixelforge and one hand-signed local test event), 51 distinct event ids; 0 failed jobs; no
warning or error in the application log.

## A. Verified before a key existed (2026-11-18)

| # | Fact | How |
|---|---|---|
| A1 | `GET /v1/pricing` shape: `rates` are decimal strings; `tokens.output[quality][size]`; `tokens.reasoning[quality].{min,max}`; `text_token_variance` is the JSON number `0.15`; `per_reference_image` 765; `characters_per_text_token` 4 | Real response saved as `tests/Fixtures/pricing.json`; all estimate tests run on it |
| A2 | Error body shape `{"error":{"type","code","message"}}` and status 401 for a missing key | One `POST /v1/generations` without a key returned `401 invalid_api_key` |
| A3 | The whole local path with the real database queue: form, reserve, queued job, real HTTP call, 401, row failed with a clear message, reserve returned | Done by hand in the browser with an empty key |
| A4 | A released submit job survives a worker started with `--tries=1` (3 releases, 0 failed jobs) | Run on the local database queue |

## B. API assumptions

| # | Assumption | Result | Evidence |
|---|---|---|---|
| B1 | `POST /v1/generations` returns 202 with a `queued` generation whose `id` starts with `gen_` | VERIFIED | 24 rows linked to a `gen_...` id; a replayed create returned `202` |
| B2 | The generation object carries `rates` as decimal strings with the four keys of `/v1/pricing` | VERIFIED | `{"input_text_tokens":"5.00","input_image_tokens":"10.00","output_tokens":"40.00","reasoning_tokens":"12.50"}` on every snapshot |
| B3 | `usage` has the four token counts as integers (plus `total_tokens`) | VERIFIED | Token columns equal the Pixelforge dashboard for the first generation (5 / 0 / 1,100 / 351) |
| B4 | `metadata.local_id` is echoed on the generation and on every webhook | VERIFIED | Present in every stored webhook payload and in `GET /v1/generations` |
| B5 | Timestamps are ISO 8601 UTC (`2026-11-18T17:46:10Z`) and `completed_at` is set on success and on failure | VERIFIED | `completed_at` and `billing_date` filled on all 23 succeeded rows |
| B6 | A replayed create with the same `Idempotency-Key` and body returns the same generation | VERIFIED | Replaying the first generation's key and body returned `202`, header `idempotent-replayed: true`, the same id, already `succeeded`; usage unchanged |
| B7 | 429 and 503 carry an integer `Retry-After` and the documented `error.code` | VERIFIED for `overloaded` (503, `retry-after: 2`) and `concurrency_limit_exceeded` (429, `Retry-After` 5, read through `PixelforgeClient` while 3 generations were running). NOT EXERCISED for `rate_limit_exceeded` and `daily_limit_exceeded` | See test 5 and test 7 |
| B8 | Content policy rejection is `400 content_policy_violation` | VERIFIED | `[blocked]` prompt; body `{"error":{"type":"invalid_request_error","code":"content_policy_violation",...}}` |
| B9 | Failed generations have `error.code` and are not billed | VERIFIED | `[fail]`: `error.code = generation_failed`, `usage` null, not counted in `/v1/usage` |
| B10 | The actual cost always falls inside the estimate range | VERIFIED on 23 of 23 | No "outside the estimate" line in the log; checked again by script |
| B11 | `GET /v1/usage` returns `data[]` with `date` and `cost_usd` (9-decimal string) per UTC day, zero days included | VERIFIED | Real response saved as `tests/Fixtures/live/usage.json`. The per-day rows have no `total_tokens` (the faked rows had one; corrected) |
| B12 | Sizes and qualities accepted by the API are exactly the keys of the pricing token table | VERIFIED | All 9 combinations of 3 sizes and 3 qualities generated once each, none rejected |

Other real shapes seen: `param` and `errors` appear only on `validation_failed`; a `ping`
event has `data.object = {"object":"ping","message":...}` with no generation id;
`X-RateLimit-Limit` and `X-RateLimit-Remaining` are on every response.

## C. Webhook assumptions

| # | Assumption | Result | Evidence |
|---|---|---|---|
| C1 | Header `Pixelforge-Signature: t=<unix seconds>,v1=<hex>` | VERIFIED by acceptance | All 50 real events passed the HMAC check and were answered 200, as were their duplicates and resends. The header text itself is not stored, so its exact spelling was not read back |
| C2 | Signed string is `<t>.<raw body>` with the `whsec_...` secret used as-is | VERIFIED | Same. Before the secret was in `.env` the same ping was answered 401, then 200 |
| C3 | Event body `{id, object, type, created_at, data: {object: <generation>}}` with the four event types | VERIFIED | Real `ping`, `generation.processing`, `generation.succeeded`, `generation.failed` saved under `tests/Fixtures/live` and run through the real route in `LivePayloadsTest` |
| C4 | A `ping` event has an `id` | VERIFIED | `evt_...` stored; dashboard shows 200 |
| C5 | `Pixelforge-Delivery-Attempt` is an integer | VERIFIED | Values 1, 3 and 4 stored |

## D. Manual test matrix, local run with the `local` key

| # | Action | Result | What was seen |
|---|---|---|---|
| 1 | `a red fox [succeed]`, medium, 1024x1024 | PASS | Queued, Processing, Succeeded without a reload; cost `0.048412500`, charge `0.060515625`, inside the estimate; image shown |
| 2 | `[fail]` | PASS | Failed, "Not charged", reserve returned, no `charge` entry |
| 3 | `[slow] [succeed]` | PASS | Processing for 63 to 82 s, then Succeeded |
| 4 | `[duplicate] [succeed]` | PASS | Dashboard shows each event delivered twice, second answered `{"received":true,"duplicate":true}`; one row per event id; one `charge` entry. Pixelforge also sent a random duplicate for a prompt without the keyword: same result |
| 5 | `[overloaded]` | PASS | Stayed Queued, 5 attempts in 39 s, then Failed "too busy, not charged"; nothing created at Pixelforge |
| 6 | `[blocked]` | PASS | Failed at once with the content policy message; not charged; nothing created |
| 7 | 5 `[slow] [succeed]` submitted within 4 s | PASS | 3 ran, 2 showed "Waiting for a free slot", all 5 finished; no 429 reached the API (`rate_limit_attempts` 0). In an earlier batch of quick submits, a sixth was refused with "You already have 5 generations in progress" |
| 8 | Dashboard: resend `generation.succeeded` | PASS | 200 `"duplicate": true`; cost and balance unchanged |
| 9 | Dashboard: resend the old `generation.processing` of a finished generation | PASS | 200 `"duplicate": true`; status stays Succeeded. A stronger case happened in test 11: a first-time `processing` event arrived after the row was already Succeeded, and nothing moved back |
| 10 | Queue worker stopped while one generation was running; a second one submitted meanwhile; worker started again | PASS | The `succeeded` webhook was stored unprocessed; after the restart both rows reached Succeeded, one charge each, 0 failed jobs |
| 11 | Webhook endpoint unreachable (local web server stopped, tunnel answering 502) with 3 `[slow]` generations running and 1 waiting | PASS | 0 webhooks received; all 4 rows reached Succeeded through the reconciler 7 to 21 s after Pixelforge finished them. When the server came back, Pixelforge's retries (attempts 3 and 4) were answered 200 and changed nothing |
| 12 | `curl -X POST <tunnel>/api/webhooks/pixelforge -d '{}'` | PASS | 401, nothing stored |
| 13 | 400-character prompt, high, 1536x1024, 2 reference URLs | PASS | Estimate `$0.3578 to $0.4674`, tooltip `$0.467343750`; actual charge `0.432831250` (88 text tokens for 400 characters) |
| 14 | Finished generations compared with Pixelforge | PASS | Script outside the app recomputed usage x rates from `GET /v1/generations` for all 23: 0 mismatches. Sum `1.511880000` = `GET /v1/usage` for 2026-11-18 = dashboard Usage page |
| 15 | Second user | PASS | A newly registered user saw an empty history |
| 16 | Admin report for today | PASS | 2026-11-18 shows Match (checked at 22 and at 23 generations) |
| 17 | New user with $0: generate, Buy credits, generate | PASS | Blocked with "Not enough credit: this generation can cost up to $0.0864 and you have $0.0000"; after Buy credits $5.0000; during the run $4.9136; settled at $4.9417. Found here: the message stayed on screen after buying credits (fixed) |

Money, for the record: provider cost `1.511880000`, charged to users `1.889850000`,
which is exactly 1.25 times the cost; every user's `balance_nano` equals the sum of
their ledger entries.

Not exercised by the live run (automated tests only): `rate_limit_exceeded`,
`daily_limit_exceeded`, a create call that times out (unknown outcome), a webhook that
arrives before the create call returns, and an actual cost outside the estimate.

A reduced version of this matrix was then run on production: section F.

## E. Environment checks

| # | What | State |
|---|---|---|
| E1 | Deployment | DONE on 2026-11-18. It differs from the first written steps in several places, listed at the top of `docs/deploy-vps.md` |
| E2 | Sub-minute scheduling | VERIFIED locally with `schedule:work` (`last_checked_at` of a running generation moved about every 30 s). VERIFIED under cron on the server: the `schedule:run` process started by cron stays alive for its whole minute, and a running generation was polled at 19:00:19, 19:00:46 and 19:01:16 UTC (F8) |
| E3 | The page at phone width | VERIFIED at 390 px in a same-origin frame (the browser window could not be resized): form, credit box, succeeded and failed cards, pagination and the admin report. No horizontal page scroll; the report table scrolls inside its own box. Not opened on a real phone |
| E4 | `.github/workflows/tests.yml` has never run on GitHub | PENDING |
| E5 | The two Pixelforge keys share the account limits (3 concurrent, 500 a day) | Do not run local tests while checking production |

## F. Production run, 2026-11-18, 18:53 to 19:02 UTC

Site: <https://pixel.sroforge.tech> (Nginx and PHP-FPM 8.3 on a shared VPS, Postgres 18,
queue worker under systemd, scheduler under cron, Cloudflare in front). Key:
`production`, used only by the deployed app.

**How it was driven.** The generations were submitted on the server as the seeded demo
user through the app's real routes (`POST /generations`, `POST /credits`,
`GET /admin/report`), handled in-process by the HTTP kernel: real middleware, CSRF
check, validation, controller, database queue, worker, Pixelforge, and webhooks coming
back through Cloudflare. They were **not** typed into a browser. So this run proves the
production back end and the data each page receives; it does not prove the production
pages on screen (sign-in form, live status without a reload, Buy credits button, phone
width). Those were seen in a browser only in the local run (D1, D17, E3).

The matrix was kept small on purpose: each keyword once, 5 at once, reference images.
Not repeated on production: dashboard resends (D8, D9), a stopped worker (D10), an
unreachable webhook endpoint (D11), a second user (D15).

| # | Action | Result | What was seen |
|---|---|---|---|
| F1 | `a red fox [succeed]`, medium, 1024x1024 | PASS | Submitted at 18:53:12, Processing at 18:53:16, Succeeded at 18:53:23, moved by the two webhooks; cost `0.057325000`, charge `0.071656250`, inside the estimate |
| F2 | `[fail]` | PASS | Processing, then Failed with the "could not generate this image, not charged" message; charge 0; reserve returned |
| F3 | `[blocked]` | PASS | Failed within a second with the content policy message; nothing created at Pixelforge; not charged |
| F4 | `[overloaded]` | PASS | Stayed waiting, 5 attempts in 34 s, then Failed "too busy"; nothing created; not charged |
| F5 | 5 `[slow] [succeed]` submitted in one go (3 sizes, 3 qualities; one with 2 reference images; one with `[duplicate]`) | PASS | 3 started within 5 s; the fourth started 4 s after the first slot was freed and the fifth 9 s after the next; all 5 succeeded; `rate_limit_attempts` 0 on every row, so no 429 reached the API |
| F6 | Reference images (inside F5): high, 1536x1024, 2 URLs | PASS | 1,530 input image tokens (2 x 765); cost `0.292590000`, charge `0.365737500`, inside the estimate |
| F7 | `[duplicate]` (inside F5) | PASS | Dashboard: `processing` and `succeeded` each delivered twice, the second answered `{"received":true,"duplicate":true}`; one stored row per event id; one `charge` entry. Pixelforge also sent unprompted duplicates for two other generations: same result |
| F8 | Reconciler under cron: one more `[slow] [succeed]`, watched every 4 s | PASS | While Processing, `last_checked_at` moved at 19:00:19, 19:00:46 and 19:01:16, so the 15-second task runs inside cron's one-minute `schedule:run`. The polling path settling a row without any webhook was not forced on production (tested locally, D11) |
| F9 | Admin report against `GET /v1/usage` (called through the app's own client) | PASS | 2026-11-18: 8 generations, provider cost `0.893562500` on both sides, Match; 2026-11-17: zero on both sides. The demo user gets 403 on `/admin/report` |
| F10 | Charges | PASS | Each of the 8 costs recomputed in SQL from the stored tokens and rates: 0 differences. Each charge equals its cost x 1.25 exactly (no fraction of a nanodollar occurred, so the round-up rule was not needed). Total charged `1.116953125` = 1.25 x `0.893562500` |
| F11 | Webhooks through Cloudflare | PASS | All 18 expected generation events arrived and were processed (19 stored with the ping, 19 distinct ids). The dashboard delivery log, read for the latest 13 deliveries, shows HTTP 200 on attempt 1 in 170 to 370 ms; nothing blocked or challenged. An unsigned POST gets 401 |
| F12 | User with $0 (the admin account), then Buy credits | PASS for the back end | Submit refused and no row created; after the purchase the balance is $5.0000 with one `purchase` entry. The on-screen message was not seen on production |
| F13 | Estimate for a 400-character prompt, high, 1536x1024, 2 reference URLs | PASS | `$0.3578` to `$0.4674`, exact maximum `0.467343750` |
| F14 | Housekeeping | PASS | 0 queued jobs, 0 failed jobs; demo balance `8.883046875` = `10` minus the 8 charges, and equals the sum of its ledger entries; 11 reserves, 11 releases |

Left on the site: 8 succeeded, 3 failed, nothing in flight. The image links expire 24
hours after completion (2026-11-19, about 19:00 UTC); after that the cards show the
"expired" placeholder.

The application log holds two errors, neither from the app at work: one from the
deployment (a command run before the migrations) and one from a typo in the temporary
script used for this run.
