<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\TwoFactorConfirmData;
use App\Events\Auth\TwoFactorEnabled;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

final readonly class ConfirmTwoFactorAction
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private Google2FA $google2fa) {}

    public function handle(User $user, string $code, int $currentTokenId): TwoFactorConfirmData
    {
        if ($user->two_factor_secret === null || ! $this->google2fa->verifyKey($user->two_factor_secret, $code, window: 1)) {
            throw ValidationException::withMessages(['code' => ['The provided code was invalid.']]);
        }

        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = strtoupper(bin2hex(random_bytes(5))); // 10 chars
            $plain[] = $code;
            $hashed[] = hash('sha256', $code);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $hashed,
        ])->save();

        // AC-001.19: enabling revokes every other device session.
        $user->tokens()->whereKeyNot($currentTokenId)->delete();

        event(new TwoFactorEnabled($user));

        return new TwoFactorConfirmData(recovery_codes: $plain);
    }
}
