<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\DeviceType;
use Spatie\LaravelData\Data;

final class CompleteOAuthData extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly string $code,
        public readonly DeviceType $device_type,
        public readonly ?string $otp = null,
    ) {}
}
