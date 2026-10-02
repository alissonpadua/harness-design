<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

/** Input DTO for POST /api/v1/auth/reset-password. */
final class ResetPasswordData extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $token,
        public readonly string $password,
    ) {}
}
