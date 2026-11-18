# Requirements checklist

Author: Abdelrhman Shalby. Last updated 2026-11-18.

- **DONE** = built and proven by the named automated test (187 tests, all passing on Postgres).
- **DONE, live** = also seen working against the real Pixelforge API on 2026-11-18 with the
  local key and a tunnel. The evidence for each "test N" is in [verify-live.md](verify-live.md).
- **NOT DONE** = not done yet, with the reason.

The local live run closed every item that needed a real key. What is still open needs
the server: deployment, the public repository, and one more run of the matrix on
production.

Test files are under `tests/`. Short names: `Nano` = Unit/NanoTest, `Cost` =
Unit/CostCalculatorTest, `Est` = Unit/EstimatorTest, `Sig` = Unit/WebhookSignatureTest,
`Client` = Feature/Pixelforge/PixelforgeClientTest, `Apply` =
Feature/Generations/ApplyProviderSnapshotTest, `Job` = Feature/Generations/SubmitGenerationJobTest,
`Hook` = Feature/Generations/WebhookTest, `Recon` = Feature/Generations/ReconcileTest,
`Page` = Feature/Generations/GenerationPageTest, `Unknown` = Feature/Generations/UnknownOutcomeTest,
`Report` = Feature/Admin/ReportTest, `Balance` = Feature/Billing/BalanceTest,
`Live` = Feature/Pixelforge/LivePayloadsTest (payloads captured from the real API).

## Product

| # | Requirement | State | Proof |
|---|---|---|---|
| P1 | Laravel + Vue + Inertia | DONE | Laravel 13, Vue 3, Inertia 3 (official starter kit) |
| P2 | Users sign in; users see only their own generations | DONE | Page: `guests_are_sent_to_login`, `the_page_shows_only_the_users_own_generations...` |
| P3 | Form: prompt, size, quality, up to 4 reference URLs | DONE | Page: `submitting_creates_one_pending_row...`, `input_is_validated_like_the_pixelforge_api` |
| P4 | Cost shown before submit | DONE | Page: `the_estimate_endpoint_returns_formatted_strings`; seen in browser |
| P5 | Charge = cost + 25%, recorded at completion from actual usage | DONE, live | Apply: `the_normal_lifecycle_records_the_exact_cost`; Cost: `markup_is_exactly_25_percent`; live test 14: 23 of 23 recomputed outside the app, charged total exactly 1.25 x cost |
| P6 | Failed generations cost nothing | DONE, live | Apply: `a_failed_generation_costs_nothing`; Balance: `a_failed_generation_gives_the_whole_reserve_back`; live tests 2, 5, 6 |
| P7 | History with live status, estimate, actual cost, image | DONE, live | Page tests; live test 1: Queued, Processing, Succeeded appeared without a reload, then the image |
| P8 | Image expiry handled | DONE | Page: `...an_expired_image_is_hidden`; placeholder seen in browser |
| P9 | Clear messages for what went wrong | DONE | Job tests assert every message; seen in browser |
| P10 | Deployed publicly with generations left on it | NOT DONE | Not deployed yet (waiting for approval). Steps: [deploy-vps.md](deploy-vps.md) |
| P11 | Public GitHub repo with README | NOT DONE | Repo is local only (not pushed, by instruction). README written |
| P12 | Admin report per day and user, matching `/v1/usage` | DONE, live | Report: all 9 tests; Live: `the_report_reads_a_real_usage_response`; live test 16: 2026-11-18 shows Match at `1.511880000` for 23 generations |
| P13 | Balances, fake Buy credits, block when unaffordable | DONE, live | Balance: all 14 tests; live test 17 |

## Money

| # | Requirement | State | Proof |
|---|---|---|---|
| M1 | Exact decimals, never float | DONE | Nano tests; Unit/NoFloatMoneyTest scans the money code |
| M2 | Cost computed as usage x rates | DONE | Cost: `each_component_of_the_worked_example` |
| M3 | Never rounded | DONE | Cost: `it_throws_instead_of_rounding...` |
| M4 | Actual cost uses the generation's own rates | DONE | Apply: `the_cost_uses_the_rates_on_the_snapshot` |
| M5 | Rates and token tables from `/v1/pricing` | DONE | Est: `pricing_is_read_from_the_fixture_not_hardcoded` (real captured response) |
| M6 | Worked example = 0.373875000 | DONE | Cost: `the_documented_worked_example_is_reproduced_exactly` |
| M7 | Markup rounding policy | DONE | Cost: `a_fraction_of_a_nanodollar_is_rounded_up...` |
| M8 | Estimate as a range | DONE | Est: `the_worked_example_is_the_maximum_estimate`, `minimum_estimate_uses_the_low_ends` |
| M9 | Cost recorded exactly once | DONE | Apply: `the_same_succeeded_snapshot_twice_changes_nothing`; Hook: `out_of_order_and_repeated_events...`; Balance: `a_repeated_succeeded_snapshot_does_not_charge_again`, `the_ledger_itself_refuses_a_second_charge...` |
| M10 | Billing day = UTC day of completion | DONE | Apply: `billing_date_is_the_utc_day_of_completion`; Report: `a_generation_is_reported_on_the_utc_day_it_completed` |
| M11 | Markup stored per generation | DONE | Apply: `the_markup_stored_on_the_row_is_used` |
| M12 | 765 image tokens per reference image, from pricing | DONE | Est: `no_reference_images_means_no_image_tokens` |
| M13 | Actual outside the estimate: charge actual, log | DONE | Code path in `ApplyProviderSnapshot::settleSucceeded`. Live: all 23 actual costs were inside their estimate, so the warning never fired |

## Provider API behaviour

| # | Requirement | State | Proof |
|---|---|---|---|
| A1 | Bearer key, one key per environment | DONE, live for the local key | Client: `create_sends_the_bearer_key...`. The production key is created at deployment |
| A2 | Only the 5 allowed params | DONE | Job: `success_links_the_row...`, `reference_images_are_left_out...` |
| A3 | Local validation mirrors the API | DONE | Page: `input_is_validated...`, `a_prompt_of_exactly_4000_characters...` |
| A4 | Idempotency-Key with identical params on every retry | DONE, live | Job: `success_links_the_row...`; payload built from stored columns; live B6: replay answered 202 with `idempotent-replayed: true` and the same generation |
| A5 | metadata maps back to the local row | DONE | Hook: `an_event_finds_an_unlinked_row_through_the_metadata_uuid` |
| A6 | 3 concurrent generations | DONE, live | Job: `the_local_gate_waits_without_calling_the_api`; live test 7: 5 submitted, 3 ran, 2 waited, all finished, no 429 |
| A7 | 429: Retry-After, growing backoff, capped at 6 | DONE | Job: `concurrency_limit_waits...`, `backoff_grows...`, `rate_limit_retries_are_capped...`. Live: the real `429 concurrency_limit_exceeded` with `Retry-After` 5 was read through the client; the per-minute and daily limits were not reached |
| A8 | 503 overloaded: retry with the same key, capped at 5 | DONE, live | Job: `overloaded_is_retried...`, `after_five_overloaded_attempts...`; live test 5 |
| A9 | daily_limit_exceeded: no retry | DONE | Job: `daily_limit_fails_immediately_without_retry` |
| A10 | content_policy_violation: tell the user | DONE, live | Job: `content_policy_rejection_fails_immediately...`; live test 6 |
| A11 | Do not hammer the 60 per minute limit | DONE | Local gate test; Recon: `a_second_run_does_not_queue_the_same_row_again` |
| A12 | Branch on error.code | DONE | Client: `documented_errors_are_mapped_by_their_code` (9 codes) |
| A13 | Timeout / unknown 5xx is ambiguous | DONE | Job: `a_timeout_is_retried_and_giving_up_makes_no_promise...`; Unknown: 4 tests |
| A14 | Lifecycle forward only; final states never change | DONE | Apply: `final_states_never_change_into_each_other`, `a_late_processing_event...` |
| A15 | Image URL expiry stored | DONE | Apply: `the_normal_lifecycle...` |

## Webhooks

| # | Requirement | State | Proof |
|---|---|---|---|
| W1 | HMAC-SHA256 signature, constant-time compare | DONE, live | Sig: all tests; Hook: `invalid_signatures_are_rejected...`; live: every real delivery verified and answered 200, an unsigned request 401 (test 12) |
| W2 | Verified on the raw body before decoding | DONE | Hook: tampered-body case |
| W3 | Old timestamps rejected (300 s) | DONE | Sig: `old_and_future_timestamps_are_invalid...` |
| W4 | No CSRF, no session | DONE | Hook: `the_route_needs_no_session_and_no_csrf_token`; real HTTP request returned 401, not 419 |
| W5 | Fast 2xx, work in a queued job | DONE | Hook: `a_valid_webhook_is_stored_once_and_queued_once` |
| W6 | Same event twice recorded once | DONE, live | Hook: `the_same_event_delivered_twice...`; live tests 4 and 8 |
| W7 | Out of order never regresses | DONE, live | Hook: `out_of_order_and_repeated_events...`; live tests 9 and 11 (a `processing` event arrived after the row was Succeeded) |
| W8 | Webhook before the POST returned | DONE | Apply: `a_pending_row_can_jump_straight_to_succeeded`; Hook: metadata test |
| W9 | Polling fallback | DONE, live | Recon: all 8 tests; live test 11: 4 generations settled with the webhook endpoint down |
| W10 | ping and unknown types acknowledged | DONE, live for ping | Hook: `ping_and_unknown_event_types...`; Live: `a_real_ping_is_stored_and_acknowledged`; dashboard ping answered 200 |
| W11 | Public webhook URL (tunnel) | DONE, live | Cloudflare quick tunnel to the local server; all deliveries of the run came through it |
| W12 | Final HTTPS URL, no redirect | NOT DONE | Needs the server: deploy steps 7 and 10 |
| W13 | Event for an unknown generation | DONE | Hook: `an_event_for_an_unknown_generation...` |

## Known gaps (accepted, written in the README)

| Gap | Effect |
|---|---|
| An unconfirmed submission is repaired only by its webhook, not by polling | If that webhook is also lost, the cost is not recorded; the report shows a Mismatch |
| A snapshot that cannot be priced stays Processing | It keeps one of the 3 slots until fixed by hand |
| A late-claimed generation is charged without a new hold | A balance can go slightly below zero |
| No first-in-first-out fairness between users waiting for a slot | Order of waiting jobs is not guaranteed |
| Phone width checked in a 390 px frame, not on a real phone | See verify-live E3 |
| While a submission is being retried after a 503, the card says "Waiting for a free slot" | Wording only; the row still ends with the correct message |

## Independent review

A separate reviewer read the money and concurrency code on 2026-11-18. It found no path to
a double charge or a wrong amount. Its findings and what was done:

| Finding | Done |
|---|---|
| "Not charged" could be shown after an earlier timeout, or after a worker died mid-call | Fixed: outcome marked unknown before the call; Unknown tests |
| Unreadable 2xx response treated as nothing | Fixed: treated like a timeout |
| Token counts coerced with `(int)` | Fixed: refused unless whole non-negative integers |
| Billing day fell back to our clock | Fixed: snapshot refused without `completed_at` |
| Signature header parsing too strict | Fixed: key=value parsing, case and spacing tolerated |
| 429 without a code rejected for good | Fixed: retried |
| Abandoned rows not polled; unpriceable rows hold a slot; no re-hold on late claim | Not fixed: listed above as known gaps |

## Found in the live run (2026-11-18)

| Finding | Done |
|---|---|
| The real per-day rows of `GET /v1/usage` have no `total_tokens`; the faked rows had one | Fixed in the test helper; a real response is now a fixture (`111af37`) |
| After Buy credits the "Not enough credit" message stayed until the next submit | Fixed (`5b9fdaf`) |
| With `composer run dev` running, 23 page tests failed: Inertia tried server-side rendering through the Vite dev server | Fixed: SSR is off in tests (`dfd8bd8`) |
| No mismatch between the real payloads and the code that reads them | Real webhook events are now fixtures and run through the real route |
