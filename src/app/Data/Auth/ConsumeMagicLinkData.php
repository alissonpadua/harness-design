<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\DeviceType;
use Spatie\LaravelData\Data;

/** Input DTO for POST /api/v1/auth/magic-link/consume. */
final class ConsumeMagicLinkData extends Data
{
    public function __construct(
        public readonly string $token,
        public readonly DeviceType $device_type,
    ) {}
}
