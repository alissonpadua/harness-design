<?php

declare(strict_types=1);

namespace App\Data\Org;

use App\Models\Organization;
use App\Models\User;
use Spatie\LaravelData\Data;

final class OrganizationData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $type,
        public readonly int $owner_id,
        public readonly ?string $logo_url,
        public readonly ?string $domain,
        public readonly string $default_member_role,
        public readonly bool $require_2fa,
        public readonly bool $invite_only,
        public readonly ?string $role,
        public readonly bool $current,
        public readonly string $created_at,
    ) {}

    public static function make(Organization $org, User $user, ?string $role = null): self
    {
        return new self(
            id: $org->id,
            name: $org->name,
            slug: $org->slug,
            type: $org->type->value,
            owner_id: $org->owner_id,
            logo_url: $org->logo_url,
            domain: $org->domain,
            default_member_role: $org->default_member_role->value,
            require_2fa: $org->require_2fa,
            invite_only: $org->invite_only,
            role: $role,
            current: $user->current_organization_id === $org->id,
            created_at: $org->created_at?->toIso8601String() ?? '',
        );
    }
}
