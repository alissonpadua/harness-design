<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use LaravelWebauthn\Services\Webauthn;

final readonly class CreatePasskeyAssertionOptionsAction
{
    /**
     * @return array{publicKey: mixed}
     */
    public function handle(?string $email): array
    {
        // Unknown email resolves to null → generic discoverable challenge (no enumeration).
        $user = $email === null ? null : User::query()->where('email', $email)->first();

        return ['publicKey' => Webauthn::prepareAssertion($user)];
    }
}
