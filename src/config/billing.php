<?php

declare(strict_types=1);

return [

    /*
    | Gateway driver: fake (tests/dev, zero network) | stripe
    */
    'driver' => env('BILLING_DRIVER', 'stripe'),

    /*
    | Stripe keys reuse services.stripe (documented in .env.example).
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'public' => env('STRIPE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    | HMAC secret for FakeGateway signed webhook payloads (tests only).
    */
    'fake_webhook_secret' => env('BILLING_FAKE_WEBHOOK_SECRET', 'fake-webhook-secret'),

    /*
    | Stripe Tax toggle (kept tax-agnostic until enabled — decision Q5).
    */
    'tax_enabled' => (bool) env('BILLING_TAX_ENABLED', false),

    'dunning_grace_days' => (int) env('BILLING_DUNNING_GRACE_DAYS', 7),

    'over_limit_grace_days' => (int) env('BILLING_OVER_LIMIT_GRACE_DAYS', 30),

    'checkout' => [
        'success_url' => env('BILLING_CHECKOUT_SUCCESS_URL', 'http://localhost:8080/api/v1/billing/checkout/return?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => env('BILLING_CHECKOUT_CANCEL_URL', 'http://localhost:8080'),
    ],
];
