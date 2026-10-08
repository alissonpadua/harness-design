<?php

declare(strict_types=1);

return [
    'paths' => ['api/*', 'admin/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_ORIGINS', ''))))),

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 3600,

    // Bearer-in-header API: cookies must NEVER ride cross-origin.
    'supports_credentials' => false,
];
