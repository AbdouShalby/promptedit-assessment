# Time log

Author: Abdelrhman Shalby

Build session on 2026-11-18, local time (UTC+2), read from the machine clock. The code
was written by an AI coding assistant working from the written plan, which is why the
elapsed time is far below what the same work takes by hand. Times are honest wall-clock
readings, rounded to the minute; steps overlapped where a build or a review ran in the
background.

| Start | End | Step |
|---|---|---|
| 18:47 | 18:58 | Read requirements and plan; scaffold the starter kit (done twice: the first attempt installed an outdated tagged version, replaced by the current main branch); local Postgres from the existing `postgres:16` image; capture the real `/v1/pricing` fixture |
| 18:58 | 19:10 | Money core, schema, API client, `ApplyProviderSnapshot`, submit job, webhooks, reconciler, with their tests; committed in 6 steps |
| 19:10 | 19:19 | Generate page (form, live estimate, history, polling); a vue-tsc problem with the kit's `Input` component inside a loop cost several minutes; browser check |
| 19:19 | 19:22 | Admin cost report and its tests |
| 19:22 | 19:28 | Credit balances, ledger, UI, tests; browser check; one real end-to-end run through the database queue (no key: 401) |
| 19:28 | 19:35 | Independent review in the background while writing README and docs; review fixes and 7 new tests; checklist |

| 19:44 | 20:02 | Live run with the local key and a tunnel: tests 1 to 13 of the matrix (all keywords, 9 size and quality combinations, reference images, 5 at once, worker stopped, webhook endpoint down, dashboard resends); real error bodies and headers read with curl |
| 20:02 | 20:10 | Second user, $0 user, admin report, phone width in a 390 px frame; cost of every generation recomputed outside the app and compared with `GET /v1/usage`; real payloads saved as fixtures with 4 tests; stale credit message fixed |
| 20:10 | 20:17 | Full suite failed beside the running dev server (Inertia SSR through Vite): fixed; dev stack restarted; one more generation and a final reconciliation; checklist, verify-live and README updated |

Total: about 48 minutes for the build and about 33 minutes for the live run, wall-clock.

Not included: writing the plan (done before the first session), setting up the Pixelforge
key and the tunnel, and what is still open: the deployment and the production run of the
matrix.
