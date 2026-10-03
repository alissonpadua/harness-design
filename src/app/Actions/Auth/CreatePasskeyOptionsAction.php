<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use LaravelWebauthn\Services\Webauthn;

final readonly class CreatePasskeyOptionsAction
{
    public const MAX_KEYS = 5;

    /**
     * @return array{publicKey: mixed}
     */
    public function handle(User $user): array
    {
        $this->guardMax($user);

        return ['publicKey' => Webauthn::prepareAttestation($user)];
    }

    public function guardMax(User $user): void
    {
        if ($user->passkeys()->count() >= self::MAX_KEYS) {
            throw ValidationException::withMessages([
                'passkeys' => ['You can register at most '.self::MAX_KEYS.' passkeys. Delete one first.'],
            ]);
        }
    }
}
