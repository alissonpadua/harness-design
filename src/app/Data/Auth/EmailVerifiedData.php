<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

/**
 * Output DTO for GET /api/v1/auth/verify-email/….
 */
final class EmailVerifiedData extends Data
{
    public function __construct(
        public readonly bool $verified,
    ) {}
}
