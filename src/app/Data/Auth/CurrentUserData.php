<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Models\User;
use Spatie\LaravelData\Data;

/** Output DTO for GET /api/v1/auth/me. */
final class CurrentUserData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $email_verified_at,
        /** @var array{impersonator_id: int, impersonator_name: string|null, started_at: string|null}|null */
        public readonly ?array $impersonation = null,
    ) {}

    public static function make(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            email_verified_at: $user->email_verified_at?->toIso8601String(),
            impersonation: $user->currentImpersonation(),
        );
    }
}
