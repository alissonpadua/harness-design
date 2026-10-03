<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Models\Organization;
use App\Models\User;

final readonly class SwitchOrganizationAction
{
    public function handle(User $user, Organization $org): void
    {
        $user->forceFill(['current_organization_id' => $org->id])->save();
    }
}
