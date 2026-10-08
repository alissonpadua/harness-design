<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Contracts\Org\PlanOrgEntitlements;
use App\Data\Plan\PlanEntitlementsData;
use App\Models\Organization;
use App\Models\OrganizationEntitlementOverride;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class SetOrgEntitlementOverrideAction
{
    public function __construct(
        private AuditSecurityEvent $audit,
        private PlanOrgEntitlements $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  subset of the 5 entitlement keys; [] clears
     * @return array<string, mixed>
     */
    public function handle(User $actor, Organization $org, array $overrides): array
    {
        $allowed = ['max_teams', 'max_members_per_org', 'webhooks', 'audit_retention_days', 'api_rate_limit_per_min'];

        if (array_diff(array_keys($overrides), $allowed) !== []) {
            throw ValidationException::withMessages(['entitlements' => ['Unknown entitlement keys in override.']]);
        }

        $before = OrganizationEntitlementOverride::query()->where('organization_id', $org->id)->value('overrides') ?? [];

        if ($overrides === []) {
            OrganizationEntitlementOverride::query()->where('organization_id', $org->id)->delete();
            $this->audit->log('org_entitlement_override', $actor, $org, ['from' => $before, 'to' => []]);

            return [];
        }

        // partial overrides merge on top of the CURRENT effective shape (plan + prior override)
        $merged = array_merge($this->entitlements->effective($org)->toArray(), $overrides);

        // validate the WHOLE merged set against the entitlement shape
        try {
            $data = PlanEntitlementsData::from($merged);
        } catch (ValidationException|\TypeError) {
            throw ValidationException::withMessages(['entitlements' => ['Overrides must form a valid complete entitlements shape.']]);
        }

        OrganizationEntitlementOverride::updateOrCreate(
            ['organization_id' => $org->id],
            ['overrides' => $data->toArray()],
        );

        $this->audit->log('org_entitlement_override', $actor, $org, ['from' => $before, 'to' => $data->toArray()]);

        return $data->toArray();
    }
}
