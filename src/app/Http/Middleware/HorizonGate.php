<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * S7: Horizon dashboard access = (a) valid Sanctum super-admin bearer that is
 * NOT an impersonated session, or (b) a 60s temporary-signed entry URL minted
 * by GET /admin/v1/ops/horizon-url, which sets an 8h HMAC "pass" cookie so the
 * dashboard SPA's further same-origin XHRs keep working without leaking the
 * bearer into query strings.
 */
final readonly class HorizonGate
{
    public const PASS_COOKIE = 'bp_horizon_pass';

    private const PASS_TTL_SECONDS = 28800; // 8h

    public function handle(Request $request, Closure $next): mixed
    {
        $entrySigned = $request->query->get('signature') !== null
            && $request->query->get('expires') !== null
            && $this->validSignature($request);

        $response = $next($request);

        if ($entrySigned && $response instanceof Response && $this->passes($request)) {
            $response->headers->setCookie(
                cookie(self::PASS_COOKIE, self::mintPass(), self::PASS_TTL_SECONDS, '/', null, $request->secure(), true, false, 'Lax')
            );
        }

        return $response;
    }

    public static function passes(Request $request): bool
    {
        $user = auth('sanctum')->user();

        if ($user instanceof User) {
            return $user->hasRole('super-admin') && ! $user->isImpersonating();
        }

        $cookie = (string) $request->cookies->get(self::PASS_COOKIE, '');

        if ($cookie !== '' && self::verifyPass($cookie)) {
            return true;
        }

        return $request->query->get('expires') !== null && $request->query->get('signature') !== null && (new self)->validSignature($request);
    }

    private function validSignature(Request $request): bool
    {
        try {
            return URL::hasValidSignature($request);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function mintPass(): string
    {
        $expires = time() + self::PASS_TTL_SECONDS;

        return $expires.'.'.hash_hmac('sha256', 'horizon-pass:'.$expires, self::key());
    }

    private static function verifyPass(string $pass): bool
    {
        [$expires, $mac] = array_pad(explode('.', $pass, 2), 2, '');

        if (! ctype_digit((string) $expires) || (int) $expires < time()) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', 'horizon-pass:'.$expires, self::key()), (string) $mac);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
