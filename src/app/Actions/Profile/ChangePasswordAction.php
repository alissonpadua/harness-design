<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\Auth\PasswordChanged;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class ChangePasswordAction
{
    public function handle(User $user, string $currentPassword, string $newPassword, int $currentTokenId): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $user->forceFill(['password' => $newPassword])->save();

        // AC-001.9: every device except this one must re-authenticate.
        $user->tokens()->whereKeyNot($currentTokenId)->delete();

        event(new PasswordChanged($user));
    }
}
