<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\AuthLinkException;
use App\Models\AuthLink;
use App\Models\User;

final readonly class VerifyEmailAction
{
    public function handle(int $userId, string $token): void
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new AuthLinkException;
        }

        AuthLink::consume($user, 'verify_email', $token);

        $user->markEmailAsVerified();
    }
}
