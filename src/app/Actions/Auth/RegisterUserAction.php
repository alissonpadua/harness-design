<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\RegisterUserData;
use App\Events\Auth\EmailVerificationRequested;
use App\Events\Auth\UserRegistered;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class RegisterUserAction
{
    public function __construct(private string $defaultRole = 'user') {}

    public function handle(RegisterUserData $data): void
    {
        $existing = User::query()->where('email', $data->email)->first();

        // Cost-parity with the create path: hashing runs either way (anti-enumeration).
        Hash::make($data->password);

        if ($existing !== null) {
            // Listener filters already-verified accounts; single filtering point.
            event(new EmailVerificationRequested($existing));

            return;
        }

        $user = User::create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password, // `hashed` cast owns hashing (T1 lesson)
        ]);

        $user->assignRole($this->defaultRole);

        event(new UserRegistered($user));
    }
}
