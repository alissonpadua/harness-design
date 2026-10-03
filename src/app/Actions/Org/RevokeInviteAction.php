<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Models\Organization;

final readonly class RevokeInviteAction
{
    public function handle(Organization $org, int $inviteId): void
    {
        $org->invites()->withoutGlobalScopes()->whereKey($inviteId)->firstOrFail()
            ->delete();
    }
}
