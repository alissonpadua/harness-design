<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Enums\OrgType;
use App\Models\Organization;
use App\Models\User;

/**
 * S1: every registration gets a private personal workspace (always free,
 * undeletable while the user exists). Idempotent by owner+type (event replay).
 */
final readonly class CreatePersonalWorkspaceAction
{
    public function handle(User $user): Organization
    {
        $existing = Organization::query()
            ->where('owner_id', $user->id)
            ->where('type', OrgType::Personal)
            ->first();

        if ($existing !== null) {
            if ($user->current_organization_id === null) {
                $user->forceFill(['current_organization_id' => $existing->id])->save();
            }

            return $existing;
        }

        $name = sprintf(config('tenancy.personal_workspace.name_pattern'), $user->name);

        // explicit slug: seeders run with WithoutModelEvents, booted() hooks off
        $org = Organization::create([
            'name' => $name,
            'slug' => Organization::uniqueSlug($name),
            'type' => OrgType::Personal,
            'owner_id' => $user->id,
        ]);

        $org->memberships()->create([
            'user_id' => $user->id,
            'role' => OrgRole::Owner->value,
            'status' => MemberStatus::Active->value,
        ]);

        $user->forceFill(['current_organization_id' => $org->id])->save();

        return $org;
    }
}
