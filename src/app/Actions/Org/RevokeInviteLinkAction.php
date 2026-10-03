<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Models\Organization;

final readonly class RevokeInviteLinkAction
{
    public function handle(Organization $org, int $linkId): void
    {
        $org->inviteLinks()->withoutGlobalScopes()->whereKey($linkId)->firstOrFail()->delete();
    }
}
