<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

final class TwoFactorEnrollData extends Data
{
    public function __construct(
        public readonly string $secret,
        public readonly string $provisioning_uri,
        public readonly string $qr_data_url,
    ) {}
}
