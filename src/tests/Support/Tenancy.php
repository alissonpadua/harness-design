<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Billing\EnsureOrgSubscription;
use App\Actions\Org\CreateOrganizationAction;
use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Data\Plan\PlanEntitlementsData;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
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
        $org = app(CreateOrganizationAction::class)->handle($owner, $name);

        self::usePlan($org, 'pro');

        return $org;
    }

    /** Attach a plan subscription (upserting a pro/business plan when missing). */
    public static function usePlan(Organization $org, string $code, array $entitlements = []): void
    {
        $defaults = [
            'pro' => ['name' => 'Pro', 'max_teams' => 5, 'max_members_per_org' => 20, 'webhooks' => true, 'audit_retention_days' => 90, 'api_rate_limit_per_min' => 600],
            'business' => ['name' => 'Business', 'max_teams' => 25, 'max_members_per_org' => 100, 'webhooks' => true, 'audit_retention_days' => 365, 'api_rate_limit_per_min' => 3000],
        ][$code] ?? ['name' => ucfirst($code), 'max_teams' => 1, 'max_members_per_org' => 3, 'webhooks' => false, 'audit_retention_days' => 0, 'api_rate_limit_per_min' => 60];

        $plan = Plan::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $defaults['name'],
                'trial_days' => 0,
                'active' => true,
                'entitlements' => new PlanEntitlementsData(
                    max_teams: $entitlements['max_teams'] ?? $defaults['max_teams'],
                    max_members_per_org: $entitlements['max_members_per_org'] ?? $defaults['max_members_per_org'],
                    webhooks: $entitlements['webhooks'] ?? $defaults['webhooks'],
                    audit_retention_days: $entitlements['audit_retention_days'] ?? $defaults['audit_retention_days'],
                    api_rate_limit_per_min: $entitlements['api_rate_limit_per_min'] ?? $defaults['api_rate_limit_per_min'],
                ),
            ]
        );

        $sub = app(EnsureOrgSubscription::class)->handle($org);
        $sub->forceFill(['plan_id' => $plan->id, 'status' => SubscriptionStatus::Active])->save();
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
