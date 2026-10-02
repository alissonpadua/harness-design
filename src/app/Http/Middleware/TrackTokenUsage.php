<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC-001.8: stamp last_used_at, write-throttled to one update per 5 minutes.
 */
final readonly class TrackTokenUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken
            && ($token->last_used_at === null || $token->last_used_at->lessThan(now()->subMinutes(5)))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $response;
    }
}
