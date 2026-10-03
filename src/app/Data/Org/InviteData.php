<?php

declare(strict_types=1);

namespace App\Data\Org;

use App\Models\OrganizationInvite;
use Spatie\LaravelData\Data;

final class InviteData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $role,
        public readonly string $expires_at,
    ) {}

    public static function make(OrganizationInvite $invite): self
    {
        return new self(
            id: $invite->id,
            email: $invite->email,
            role: $invite->role->value,
            expires_at: $invite->expires_at->toIso8601String(),
        );
    }
}
