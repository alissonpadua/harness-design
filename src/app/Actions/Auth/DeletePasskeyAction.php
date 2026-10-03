<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

final readonly class DeletePasskeyAction
{
    public function handle(User $user, int $passkeyId): void
    {
        // own scope → foreign ids 404 (no IDOR)
        $user->passkeys()->findOrFail($passkeyId)->delete();
    }
}
