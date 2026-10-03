<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\Auth\EmailChangeRequested;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class RequestEmailChangeAction
{
    public function handle(User $user, string $email, string $password): void
    {
        $email = strtolower($email);

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['The provided password is incorrect.']]);
        }

        if ($email === strtolower($user->email)) {
            throw ValidationException::withMessages(['email' => ['This is already your email address.']]);
        }

        event(new EmailChangeRequested($user, $email));
    }
}
