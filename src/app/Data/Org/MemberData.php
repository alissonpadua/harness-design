<?php

declare(strict_types=1);

namespace App\Data\Org;

use App\Models\User;
use Spatie\LaravelData\Data;

final class MemberData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly ?string $joined_at,
    ) {}

    public static function make(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->membership?->role->value ?? '',
            status: $user->membership?->status->value ?? '',
            joined_at: $user->membership?->created_at?->toIso8601String(),
        );
    }
}
