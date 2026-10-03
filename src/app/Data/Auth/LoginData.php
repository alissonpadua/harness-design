<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\DeviceType;
use Spatie\LaravelData\Data;

/** Input DTO for POST /api/v1/auth/login. */
final class LoginData extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly DeviceType $device_type,
        public readonly ?string $otp = null,
    ) {}
}
