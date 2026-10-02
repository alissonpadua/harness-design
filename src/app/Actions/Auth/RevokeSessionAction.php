<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

final readonly class RevokeSessionAction
{
    public function handle(User $user, int $sessionId): void
    {
        // Scoped to own tokens: foreign ids 404 via findOrFail (no IDOR).
        $user->tokens()->findOrFail($sessionId)->delete();
    }
}
