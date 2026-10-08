<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-Id', '');
        // Spec 006: reuse ONLY safe-shaped client ids; anything else is replaced
        // (never echo attacker-controlled bytes into headers/logs verbatim).
        $id = preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
