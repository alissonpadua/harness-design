<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Q5 (corrected): the impersonated session has NO auto-expiry — it lives
 * until an admin stops it. One active impersonation per (admin,target) pair:
 * starting again revokes the previous token first.
 */
final readonly class StartImpersonationAction
{
    public const ABILITY = 'impersonation';

    public function __construct(private AuditSecurityEvent $audit) {}

    /**
     * @return array{token: string, token_id: int, target: User, started_at: string}
     */
    public function handle(User $admin, User $target): array
    {
        if ($admin->is($target)) {
            throw ValidationException::withMessages(['user' => ['You cannot impersonate yourself.']]);
        }

        if ($target->isSuspended()) {
            throw ValidationException::withMessages(['user' => ['Cannot impersonate a suspended account.']]);
        }

        PersonalAccessToken::query()->where('tokenable_type', $target->getMorphClass())->where('tokenable_id', $target->id)->where('impersonator_id', $admin->id)->delete();

        $token = $target->createToken('impersonation by '.$admin->email, [self::ABILITY]);
        $token->accessToken->forceFill(['impersonator_id' => $admin->id])->save();

        $this->audit->log('impersonation_start', $admin, $target, ['token_id' => $token->accessToken->id]);

        return [
            'token' => $token->plainTextToken,
            'token_id' => $token->accessToken->id,
            'target' => $target,
            'started_at' => now()->toIso8601String(),
        ];
    }
}
