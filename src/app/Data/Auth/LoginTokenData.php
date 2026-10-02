<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

/** Output DTO for a successful login: the plaintext token, shown once. */
final class LoginTokenData extends Data
{
    public function __construct(
        public readonly string $token,
        public readonly string $device_type,
    ) {}
}
