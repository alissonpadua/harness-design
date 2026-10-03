<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

final class TwoFactorConfirmData extends Data
{
    /**
     * @param  array<int, string>  $recovery_codes  shown once
     */
    public function __construct(
        public readonly array $recovery_codes,
    ) {}
}
