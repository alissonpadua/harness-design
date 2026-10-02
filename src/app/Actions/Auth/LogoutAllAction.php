<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

final readonly class LogoutAllAction
{
    public function handle(User $user): int
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return $count;
    }
}
