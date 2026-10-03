<?php

declare(strict_types=1);

namespace App\Data\Org;

use App\Models\OrganizationInviteLink;
use Spatie\LaravelData\Data;

final class InviteLinkData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly int $uses,
        public readonly ?int $max_uses,
        public readonly ?string $expires_at,
    ) {}

    public static function make(OrganizationInviteLink $link): self
    {
        return new self(
            id: $link->id,
            role: $link->role->value,
            uses: $link->uses,
            max_uses: $link->max_uses,
            expires_at: $link->expires_at?->toIso8601String(),
        );
    }
}
