<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Contracts\Org\OrgEntitlements;
use App\Enums\OrgRole;
use App\Events\Org\MemberInvited;
use App\Exceptions\SubscriptionRequiredException;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class InviteMemberAction
{
    public function __construct(private OrgEntitlements $entitlements) {}

    public function handle(User $inviter, Organization $org, string $email, OrgRole $role): OrganizationInvite
    {
        $email = strtolower($email);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $org->membershipFor($existing) !== null) {
            throw ValidationException::withMessages(['email' => ['This person is already a member.']]);
        }

        if ($org->members()->count() >= $this->entitlements->maxMembers($org)) {
            throw new SubscriptionRequiredException('Member limit reached.');
        }

        // newest invite wins: drop older pending invites for the same address
        OrganizationInvite::query()->withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('email', $email)
            ->whereNull('used_at')
            ->delete();

        $invite = OrganizationInvite::makeWithToken();
        $invite->forceFill([
            'organization_id' => $org->id,
            'email' => $email,
            'role' => $role,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays((int) config('tenancy.invites.ttl_days')),
        ])->save();

        event(new MemberInvited($org, $invite));

        return $invite;
    }
}
