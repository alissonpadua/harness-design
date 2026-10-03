<?php

declare(strict_types=1);

namespace App\Data\Org;

use App\Models\OrganizationInviteLink;
use Spatie\LaravelData\Data;

/** Shown once at creation: carries the raw token. */
final class InviteLinkSecretData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $token,
        public readonly string $url,
        public readonly string $role,
        public readonly int $uses,
    ) {}

    public static function make(OrganizationInviteLink $link): self
    {
        return new self(
            id: $link->id,
            token: $link->token(),
            url: rtrim((string) config('app.url'), '/').'/join/'.$link->token(),
            role: $link->role->value,
            uses: (int) $link->uses,
        );
    }
}
