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
        $id = $request->header('X-Request-Id') ?: (string) Str::ulid();
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
