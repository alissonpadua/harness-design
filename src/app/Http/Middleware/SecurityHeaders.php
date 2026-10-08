<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 006 headers plane: nosniff / XFO DENY / Referrer-Policy always;
 * HSTS only over HTTPS; minimal CSP except documented HTML surfaces (D3).
 */
final readonly class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', (string) config('security.hsts'));
        }

        if (! $this->cspExempt($request)) {
            $response->headers->set('Content-Security-Policy', (string) config('security.csp'));
        }

        return $response;
    }

    private function cspExempt(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ((array) config('security.csp_exempt') as $prefix) {
            if ($path === (string) $prefix || str_starts_with($path, (string) $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
