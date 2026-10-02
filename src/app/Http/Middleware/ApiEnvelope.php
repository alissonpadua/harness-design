<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Success envelope: raw JSON arrays from API routes are wrapped under "data".
 * Resources/paginators already emit "data" and pass through untouched.
 */
final readonly class ApiEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $request->is('api/*', 'admin/*')) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || array_key_exists('data', $payload) || array_key_exists('message', $payload)) {
            return $response;
        }

        $response->setData(['data' => $payload]);

        return $response;
    }
}
