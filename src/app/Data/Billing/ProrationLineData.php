<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class ProrationLineData extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly int $amount,
        public readonly string $currency,
    ) {}
}
