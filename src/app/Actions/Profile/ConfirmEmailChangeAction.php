<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\Auth\EmailChanged;
use App\Exceptions\AuthLinkException;
use App\Models\AuthLink;
use App\Models\User;

final readonly class ConfirmEmailChangeAction
{
    public function handle(int $userId, string $token): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if ($user === null) {
            throw new AuthLinkException;
        }

        $link = AuthLink::consume($user, 'confirm_email_change', $token);

        $previous = $user->email;
        $user->forceFill([
            'email' => (string) $link->email,
            'email_verified_at' => now(),
        ])->save();

        // AC-001.9: an applied email change is a full re-auth event.
        $user->tokens()->delete();

        event(new EmailChanged($user, $previous));
    }
}
