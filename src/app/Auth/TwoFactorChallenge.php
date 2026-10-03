<?php

declare(strict_types=1);

namespace App\Auth;

use App\Events\Auth\RecoveryCodeUsed;
use App\Exceptions\TwoFactorRequiredException;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Shared otp/recovery verification for every challenged surface
 * (login AC-001.18, magic-link consume; OAuth in T7).
 */
final readonly class TwoFactorChallenge
{
    public function __construct(private Google2FA $google2fa) {}

    /**
     * @return 'totp'|'recovery'|null null = user has no confirmed 2FA
     */
    public function verify(User $user, ?string $otp): ?string
    {
        if ($user->two_factor_confirmed_at === null) {
            return null;
        }

        if ($otp === null || $otp === '') {
            throw new TwoFactorRequiredException;
        }

        if ($this->google2fa->verifyKey((string) $user->two_factor_secret, $otp, window: 1)) {
            return 'totp';
        }

        $normalized = strtoupper(str_replace(['-', ' '], '', $otp));
        $hash = hash('sha256', $normalized);
        $codes = $user->two_factor_recovery_codes ?? [];

        $index = array_search($hash, $codes, true);

        if ($index === false) {
            throw ValidationException::withMessages([
                'otp' => ['The provided two factor authentication code was invalid.'],
            ]);
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        event(new RecoveryCodeUsed($user));

        return 'recovery';
    }
}
