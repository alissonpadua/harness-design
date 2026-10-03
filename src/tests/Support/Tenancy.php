<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Org\CreateOrganizationAction;
use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;

final class Tenancy
{
    public static function user(string $email = 'ada@example.com'): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'Str0ng!Passw0rd',
        ]);

        $token = $user->createToken('web', ['*'])->plainTextToken;

        return [$user, $token];
    }

    public static function personalWorkspace(User $user): Organization
    {
        return app(CreatePersonalWorkspaceAction::class)->handle($user);
    }

    public static function org(User $owner, string $name = 'Acme'): Organization
    {
        return app(CreateOrganizationAction::class)->handle($owner, $name);
    }

    public static function addMember(Organization $org, User $user, OrgRole $role = OrgRole::Member, MemberStatus $status = MemberStatus::Active): void
    {
        $org->memberships()->create([
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => $status->value,
        ]);
    }

    public static function switchTo(User $user, Organization $org): void
    {
        $user->forceFill(['current_organization_id' => $org->id])->save();
    }
}
