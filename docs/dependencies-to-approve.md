# Dependencies

Author: Abdelrhman Shalby

**Nothing needs approval.** No package was added beyond what the official Laravel Vue
starter kit installs (`composer.json` and `package.json` are the kit's own lists).

Things that were done with built-in features instead of a package:

| Need | Used | Package avoided |
|---|---|---|
| Exact money | PHP integers (nanodollars) | brick/money, bcmath wrappers |
| HTTP client and fakes | Laravel `Http` facade | Guzzle wrappers, SDKs |
| Queue, cache, sessions | `database` drivers | Redis, Horizon |
| Live updates | Inertia `usePoll` | Reverb, Pusher, Echo |
| Debounce in the estimate box | `@vueuse/core` (already in the kit) | lodash |
| Webhook signature | `hash_hmac`, `hash_equals` | spatie/laravel-webhook-client |

Local-only requirement (not a dependency of the app): the PHP `pdo_pgsql` extension must
be enabled. It ships with PHP on Windows but is switched off in the default `php.ini`.

Server packages are listed in [deploy-vps.md](deploy-vps.md) step 1; they are standard
Ubuntu packages and are installed only if missing.
