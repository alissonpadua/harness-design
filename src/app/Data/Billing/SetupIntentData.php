<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Spatie\LaravelData\Data;

final class SetupIntentData extends Data
{
    public function __construct(
        public readonly string $clientSecret,
        public readonly string $url,
    ) {}
}
