<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * AC-001.10 — silent no-op for unknown/deleted emails (generic 202 upstream).
 */
final readonly class SendPasswordResetLinkAction
{
    public function handle(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            Password::broker()->sendResetLink(['email' => $email]);
        }
    }
}
