<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

final class PasskeyListData extends Data
{
    /**
     * @param  array<int, PasskeyData>  $passkeys
     */
    public function __construct(
        public readonly array $passkeys,
    ) {}
}
