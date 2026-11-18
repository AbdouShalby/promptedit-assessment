# What must be verified against the live Pixelforge API

Author: Abdelrhman Shalby

The app was built without an API key. Everything below was coded from the API
documentation and tested with faked HTTP responses shaped like the docs. Each item says
what is assumed, where the assumption lives in the code, and how to check it.

Legend: **VERIFIED** = checked against the real API. **PENDING** = needs a real key, a
tunnel, or the server.

## A. Already verified live (2026-11-18, no key needed)

| # | Fact | How |
|---|---|---|
| A1 | `GET /v1/pricing` shape: `rates` are decimal strings; `tokens.output[quality][size]`; `tokens.reasoning[quality].{min,max}`; `text_token_variance` is the JSON number `0.15`; `per_reference_image` 765; `characters_per_text_token` 4 | Real response saved as `tests/Fixtures/pricing.json`; all estimate tests run on it |
| A2 | Error body shape `{"error":{"type","code","message"}}` and status 401 for a missing key | One `POST /v1/generations` without a key returned `401 invalid_api_key`. `param` and `errors` were absent from that response; the client reads only `code` and `message` |
| A3 | The whole local path with the real database queue: form, reserve, queued job, real HTTP call, 401, row failed with a clear message, reserve returned | Done by hand in the browser with an empty key |
| A4 | A released submit job survives a worker started with `--tries=1` (3 releases, 0 failed jobs) | Run on the local database queue |

## B. API assumptions (PENDING: need a key)

| # | Assumption | Where in the code | How to check | If it is wrong |
|---|---|---|---|---|
| B1 | `POST /v1/generations` returns 202 with a generation object whose `status` is `queued` and whose `id` starts with `gen_` | `SubmitGeneration`, `ApplyProviderSnapshot::apply` | Generate once; the row must become Queued with a `provider_id` | Adjust the keys read in `apply()` |
| B2 | The generation object carries `rates` as decimal strings with the same four keys as `/v1/pricing` | `CostCalculator::providerCost` | `select rates from generations` after one success | A JSON number such as `12.5` also parses; a different key name throws and the job retries (row stays Processing: look in `storage/logs`) |
| B3 | `usage` has `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` as integers | `ApplyProviderSnapshot::settleSucceeded` | Compare one row's token columns with the Pixelforge dashboard | Rename the keys in `CostCalculator::TOKEN_TYPES` |
| B4 | `metadata.local_id` is echoed on the generation and on every webhook | `ProcessWebhookEvent::findGeneration` | `select payload->'data'->'object'->'metadata' from webhook_events limit 1` | Only the "webhook before POST returned" path depends on it |
| B5 | Timestamps are ISO 8601 UTC and `completed_at` is set on success | `ApplyProviderSnapshot::time` | `completed_at` and `billing_date` filled after a success | Offsets are converted to UTC already (tested) |
| B6 | A replayed create with the same `Idempotency-Key` and body returns the same generation (202, `Idempotent-Replayed: true`) | `SubmitGeneration` retries | Test 10 below (stop the worker mid-submit), or re-dispatch a submit job for a queued row: nothing changes | Core assumption of the retry design |
| B7 | 429 and 503 responses carry an integer `Retry-After` header and `error.code` values `rate_limit_exceeded`, `concurrency_limit_exceeded`, `daily_limit_exceeded`, `overloaded` | `PixelforgeClient::send`, `SubmitGeneration::handleError` | `[overloaded]` prompt; 5 quick `[slow]` generations from two environments | A missing header falls back to the backoff; an unknown code fails the row as "problem on our side" and is logged |
| B8 | Content policy rejection is `400 content_policy_violation` | `SubmitGeneration::handleError` | `[blocked]` prompt | Same fallback as B7 |
| B9 | Failed generations have `error.code` and are not billed | `ApplyProviderSnapshot::settleFailed` | `[fail]` prompt, then check `/v1/usage` | - |
| B10 | The actual cost always falls inside the estimate range (text tokens within 15% using floor/ceil on the bounds) | `Estimator` | `grep "outside the estimate" storage/logs/laravel.log` must find nothing | The charge is still the actual cost; only the estimate bounds would need tuning |
| B11 | `GET /v1/usage` returns `data[]` with `date` (YYYY-MM-DD) and `cost_usd` (9-decimal string) per UTC day, zero days included | `ReportController::providerUsage` | Open `/admin/report` after a few generations: every day shows Match | Adjust the two keys read there |
| B12 | Sizes and qualities accepted by the API are exactly the keys of the pricing token table | `EstimateGenerationRequest::rules` | Generate with each size and quality once | A 422 would fail the row as "problem on our side" |

## C. Webhook assumptions (PENDING: need a key and a public URL)

| # | Assumption | Where | How to check | If it is wrong |
|---|---|---|---|---|
| C1 | Header is exactly `Pixelforge-Signature: t=<unix seconds>,v1=<64 lowercase hex>` and nothing else | `WebhookSignature` (strict regular expression) | Dashboard `ping` must show 200. If it shows 401, compare the real header with the pattern | **Most likely place for a first-run failure.** Loosen the pattern (extra fields, spaces, upper case) |
| C2 | Signed string is `<t>.<raw body>` with the key's `whsec_...` secret used as-is (not base64-decoded) | `WebhookSignature` | Same ping | Decode the secret before `hash_hmac` |
| C3 | Event body is `{id, object, type, created_at, data: {object: <generation>}}` with `type` in `generation.processing`, `generation.succeeded`, `generation.failed`, `ping` | `PixelforgeWebhookController`, `ProcessWebhookEvent` | `select type, processed_at from webhook_events` | - |
| C4 | A `ping` event has an `id` (it is stored for dedupe); a ping without an id would be answered 400 | `PixelforgeWebhookController` | Dashboard `ping` shows 200 | Accept events without an id for `ping` |
| C5 | `Pixelforge-Delivery-Attempt` is an integer | Controller (stored for debugging only) | `select delivery_attempt from webhook_events` | Cosmetic |

## D. Manual test matrix (PENDING)

Run locally through a tunnel with the local key, then again on production with the
production key. Record the date and the result in the README.

| # | Action | Expected |
|---|---|---|
| 1 | Prompt `a red fox [succeed]`, medium, 1024x1024 | Queued, Processing, Succeeded live; actual cost inside the estimate; image shown |
| 2 | Prompt with `[fail]` | Ends Failed, "Not charged", reserve returned |
| 3 | Prompt with `[slow] [succeed]` | Processing for 60 to 90 s, then Succeeded |
| 4 | Prompt with `[duplicate] [succeed]` | Dashboard shows each event delivered twice, all answered 200; one row per event id; one `charge` entry |
| 5 | Prompt with `[overloaded]` | Stays Queued while retrying (2, 4, 8, 16 s), then Failed "too busy, not charged" after 5 attempts; nothing created at Pixelforge |
| 6 | Prompt with `[blocked]` | Failed with the content policy message; not charged |
| 7 | Submit 5 `[slow] [succeed]` generations quickly | 3 run, 2 show "Waiting for a free slot", all 5 finish; no error shown |
| 8 | Dashboard: resend a `generation.succeeded` delivery | 200 with `"duplicate": true`; cost and balance unchanged |
| 9 | Dashboard: resend an old `generation.processing` for a finished generation | 200; status stays Succeeded |
| 10 | Stop the queue worker, generate, wait until Pixelforge finishes, start the worker | The row catches up |
| 11 | Point the key's webhook URL at a dead path (or stop the tunnel), generate | The row still reaches Succeeded through the reconciler within about a minute |
| 12 | `curl -X POST <app>/api/webhooks/pixelforge -d '{}'` | 401, nothing stored |
| 13 | 400-character prompt, high, 1536x1024, 2 reference URLs | Estimate maximum `$0.4674` (tooltip `0.467343750`) |
| 14 | Compare 3 finished generations with the Pixelforge dashboard | Provider cost identical to 9 decimals: `select provider_id, provider_cost_nano, charge_nano from generations` |
| 15 | Second browser, second user | Sees only own history |
| 16 | Admin report for today | Every day shows Match |
| 17 | New user with $0: generate, then Buy credits, then generate | Blocked with a clear message; then allowed; balance drops by the maximum estimate, then settles to the actual cost |

## E. Environment checks (PENDING)

| # | What | How |
|---|---|---|
| E1 | The steps in `docs/deploy-vps.md` have never been run | Follow them one by one on the server |
| E2 | Sub-minute scheduling under cron on the server | `tail storage/logs/laravel.log` during test 11, or `select last_checked_at from generations` changing about every 20 s for a row in flight |
| E3 | The page at phone width | Checked on desktop only (empty state, validation error, queued, processing, succeeded, failed, expired image, balance, admin report). The layout uses one column below the `lg` breakpoint; open it on a phone once |
| E4 | `.github/workflows/tests.yml` (Postgres service added) has never run on GitHub | Push and watch the first run |
| E5 | The two Pixelforge keys share the account limits (3 concurrent, 500 a day) | Do not run local tests while checking production |
