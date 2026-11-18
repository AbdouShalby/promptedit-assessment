<?php

// Accounts created by the database seeder for reviewers. Values come from the
// environment only; nothing is seeded for an account whose password is empty.
return [
    'user' => [
        'email' => env('DEMO_USER_EMAIL'),
        'password' => env('DEMO_USER_PASSWORD'),
        'credit_nano' => 10_000_000_000,   // the demo user starts with $10; everyone else starts at $0
    ],
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
