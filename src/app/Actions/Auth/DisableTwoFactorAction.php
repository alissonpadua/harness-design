<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Events\Auth\TwoFactorDisabled;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class DisableTwoFactorAction
{
    public function handle(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['The provided password is incorrect.']]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        event(new TwoFactorDisabled($user));
    }
}
