<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AC-005.3 exclusions: an impersonated session cannot touch the admin plane,
 * re-verification/security settings, org destruction or ownership transfer.
 */
final readonly class EnsureNotImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isImpersonating()) {
            throw new HttpException(403, 'Impersonated sessions cannot perform this action.');
        }

        return $next($request);
    }
}
