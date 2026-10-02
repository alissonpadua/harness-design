<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

final readonly class LogoutAction
{
    public function handle(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
