<?php

declare(strict_types=1);

return [

    /*
    | Shared email layout theming (spec 004 Q6).
    */
    'theme' => [
        'accent' => env('NOTIFY_ACCENT_COLOR', '#4f46e5'),
        'from_name' => env('NOTIFY_FROM_NAME', env('APP_NAME', 'Boilerplate')),
    ],

    /*
    | Inbox retention: newest N rows persisted per user (AC-004.7).
    */
    'inbox_keep' => (int) env('NOTIFY_INBOX_KEEP', 100),

    /*
    | Failed-send sweep (Q3): cadence + attempt cap + base backoff minutes.
    */
    'retry' => [
        'max_attempts' => 3,
        'backoff_minutes' => 15,
    ],

    'trial_reminder_days' => 3,
];
