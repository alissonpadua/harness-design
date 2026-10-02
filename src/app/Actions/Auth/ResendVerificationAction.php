<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Events\Auth\EmailVerificationRequested;
use App\Models\User;

final readonly class ResendVerificationAction
{
    public function handle(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        // Existence of the account must not leak through behavior differences;
        // the listener filters verified users out.
        if ($user !== null) {
            event(new EmailVerificationRequested($user));
        }
    }
}
