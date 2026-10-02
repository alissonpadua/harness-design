<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Laravel\Sanctum\PersonalAccessToken;
use Spatie\LaravelData\Data;

/** One active device session (AC-001.8). */
final class SessionData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $device_type,
        public readonly ?string $ip_address,
        public readonly ?string $user_agent,
        public readonly ?string $created_at,
        public readonly ?string $last_used_at,
        public readonly bool $current,
    ) {}

    public static function make(PersonalAccessToken $token, int $currentId): self
    {
        return new self(
            id: $token->id,
            device_type: $token->device_type,
            ip_address: $token->ip_address,
            user_agent: $token->user_agent,
            created_at: $token->created_at?->toIso8601String(),
            last_used_at: $token->last_used_at?->toIso8601String(),
            current: $token->id === $currentId,
        );
    }
}
