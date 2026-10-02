<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public API Docs
    |--------------------------------------------------------------------------
    |
    | When true, GET /docs redirects to Scramble's UI (/docs/api) without
    | authentication. Set DOCS_PUBLIC=false in production.
    |
    */

    'docs_public' => (bool) env('DOCS_PUBLIC', false),

];
