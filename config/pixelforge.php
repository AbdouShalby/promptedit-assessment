<?php

return [

    // One API key per environment: each key has its own webhook URL, signing secret and /v1/usage.
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Webhook timestamps further than this many seconds from now are rejected.
    'webhook_tolerance' => 300,

    // What users pay on top of the Pixelforge cost, in basis points (2500 = 25%).
    'markup_bps' => (int) env('PIXELFORGE_MARKUP_BPS', 2500),

    // Pixelforge allows this many queued/processing generations per account.
    'max_concurrent' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),

    // Submission: how long a generation may wait locally, and how many real API retries we make.
    'submit_deadline_seconds' => 600,
    'max_overload_attempts' => 5,       // 503 overloaded, timeouts, unknown 5xx
    'max_rate_limit_attempts' => 6,     // 429 rate limit / concurrency limit

    // One user cannot fill the shared queue.
    'max_in_flight_per_user' => 5,

    'http_timeout' => 10,
    'pricing_cache_seconds' => 300,

    // Polling fallback: re-check a generation after this many seconds without news.
    'stale_after_seconds' => 20,
    'stale_pending_after_seconds' => 90,

    // Fake "Buy credits" pack: $5.00 in nanodollars.
    'credit_pack_nano' => 5_000_000_000,

];
