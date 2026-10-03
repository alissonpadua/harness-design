<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Contracts\Org\OrgEntitlements;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Enums\OrgType;
use App\Events\Org\OrgCreated;
use App\Exceptions\SubscriptionRequiredException;
use App\Models\Organization;
use App\Models\User;

final readonly class CreateOrganizationAction
{
    public function __construct(private OrgEntitlements $entitlements) {}

    public function handle(User $creator, string $name): Organization
    {
        $used = Organization::query()
            ->where('type', OrgType::Team)
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $creator->id))
            ->count();

        if ($used >= $this->entitlements->maxTeams($creator)) {
            throw new SubscriptionRequiredException('Team limit reached.');
        }

        $org = Organization::create([
            'name' => $name,
            'type' => OrgType::Team,
            'owner_id' => $creator->id,
        ]);

        $org->memberships()->create([
            'user_id' => $creator->id,
            'role' => OrgRole::Owner->value,
            'status' => MemberStatus::Active->value,
        ]);

        event(new OrgCreated($org));

        return $org;
    }
}
