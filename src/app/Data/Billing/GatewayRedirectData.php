<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class GatewayRedirectData extends Data
{
    public function __construct(
        public readonly string $url,
        public readonly string $gatewayRef,
    ) {}
}
