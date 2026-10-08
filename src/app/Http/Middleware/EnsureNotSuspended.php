<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth: a suspended account cannot use any live bearer session
 * even if issued before the suspension (suspend revokes tokens; this is the
 * belt, AC-005.12).
 */
final readonly class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuspended()) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        return $next($request);
    }
}
