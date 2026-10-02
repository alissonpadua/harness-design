<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Spatie\LaravelData\Data;

/** Output DTO for GET /api/v1/auth/sessions. */
final class SessionListData extends Data
{
    /**
     * @param  array<int, SessionData>  $sessions
     */
    public function __construct(
        public readonly array $sessions,
    ) {}
}
