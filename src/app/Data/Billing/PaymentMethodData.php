<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class PaymentMethodData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $brand,
        public readonly string $last4,
        public readonly int $expMonth,
        public readonly int $expYear,
        public readonly bool $isDefault,
    ) {}
}
