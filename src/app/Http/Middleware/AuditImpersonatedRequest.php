<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Audit\AuditSecurityEvent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every ALLOWED impersonated request is audit-logged (AC-005.3), captured on
 * terminate so the response code is meaningful.
 */
final readonly class AuditImpersonatedRequest
{
    public function __construct(private readonly AuditSecurityEvent $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User || ! $user->isImpersonating()) {
            return $next($request);
        }

        /** @var PersonalAccessToken $token */
        $token = $user->currentAccessToken();

        $response = $next($request);

        $this->audit->log('impersonated_request', $user, null, [
            'impersonator_id' => (int) $token->getAttribute('impersonator_id'),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
