<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scramble registers /docs/api and /docs/api.json unconditionally (v0.13);
 * this gate hides the whole docs surface unless app.docs_public is true.
 * Spec 005 adds the authenticated super-admin escape hatch for production.
 */
final readonly class EnsureDocsVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.docs_public') && $request->is('docs', 'docs/*')) {
            abort(404);
        }

        return $next($request);
    }
}
