<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Org\OrgEntitlements;
use App\Enums\OrgType;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;

/**
 * Policy A (AC-003.21/.22): stamp over_limit_until when CURRENT usage of the
 * org-owner's teams or the org's members exceeds the plan caps; clear it when
 * compliant. Never deletes anything; add-blocks fall out of plain cap checks.
 */
final readonly class EvaluateOverLimit
{
    public function __construct(private OrgEntitlements $entitlements) {}

    public function handle(BillingSubscription $sub): BillingSubscription
    {
        $org = $sub->organization;

        if ($org === null) {
            return $sub;
        }

        $owner = $org->owner;
        $exceeded = $org->members()->count() > $this->entitlements->maxMembers($org)
            || ($owner !== null && $this->ownerTeams($owner) > $this->entitlements->maxTeams($owner));

        $until = $sub->over_limit_until;

        if ($exceeded && $until === null) {
            $until = now()->addDays((int) config('billing.over_limit_grace_days'));
        }

        if (! $exceeded) {
            $until = null;
        }

        $same = ($until === null && $sub->over_limit_until === null)
            || ($until !== null && $sub->over_limit_until !== null && $until->equalTo($sub->over_limit_until));

        if (! $same) {
            $sub->forceFill(['over_limit_until' => $until])->save();
        }

        return $sub;
    }

    private function ownerTeams(User $owner): int
    {
        return Organization::query()
            ->where('type', OrgType::Team)
            ->where('owner_id', $owner->id)
            ->count();
    }
}
