<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\ResetPasswordData;
use App\Events\Auth\PasswordChanged;
use App\Models\AuthLink;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

final readonly class ResetPasswordAction
{
    public function handle(ResetPasswordData $data): void
    {
        $status = Password::broker()->reset(
            ['email' => $data->email, 'token' => $data->token, 'password' => $data->password],
            function (User $user, string $password): void {
                // `hashed` cast applies (idempotent for broker-supplied raw input).
                $user->forceFill(['password' => $password])->save();

                // AC-001.9/.10: every previous credential dies with the password.
                $user->tokens()->delete();
                AuthLink::revokeOutstanding($user->id, 'verify_email');
                AuthLink::revokeOutstanding($user->id, 'magic_link');

                event(new PasswordChanged($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            // INVALID_USER and INVALID_TOKEN collapse to one identical 422.
            throw ValidationException::withMessages([
                'token' => ['This password reset token is invalid.'],
            ]);
        }
    }
}
