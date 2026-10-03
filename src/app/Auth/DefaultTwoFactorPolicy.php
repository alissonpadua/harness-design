<?php

declare(strict_types=1);

namespace App\Auth;

use App\Contracts\TwoFactorPolicy;
use App\Models\User;

final readonly class DefaultTwoFactorPolicy implements TwoFactorPolicy
{
    public function requires(User $user): bool
    {
        return false;
    }
}
