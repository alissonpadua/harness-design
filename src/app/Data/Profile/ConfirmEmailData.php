<?php

declare(strict_types=1);

namespace App\Data\Profile;

use Spatie\LaravelData\Data;

final class ConfirmEmailData extends Data
{
    public function __construct(
        public readonly bool $confirmed,
    ) {}
}
