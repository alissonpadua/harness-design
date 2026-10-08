<?php

declare(strict_types=1);

/*
 * Security header plane (spec 006). Values are LOCKED acceptance facts —
 * changing them is a spec amendment, not a config tweak.
 */

return [
    'csp' => "default-src 'none'; frame-ancestors 'none'",

    // Route-path prefixes exempt from CSP (HTML dev surfaces — deviation D3).
    'csp_exempt' => ['docs', 'docs/api', 'docs/api.json', 'swagger-ui'],

    'hsts' => 'max-age=31536000; includeSubDomains',
];
