<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ChangeOrgPlanAction;
use App\Actions\Admin\RestoreOrganizationAction;
use App\Actions\Admin\SetOrgEntitlementOverrideAction;
use App\Contracts\Org\PlanOrgEntitlements;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeOrgPlanRequest;
use App\Http\Requests\Admin\SetEntitlementOverrideRequest;
use App\Http\Requests\Admin\UserActionRequest;
use App\Models\Organization;
use App\Models\OrganizationEntitlementOverride;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{organizations: array<int, object>, current_page: int, last_page: int}}')]
    public function index(Request $request): JsonResponse
    {
        $q = $request->query('q') !== null ? (string) $request->query('q') : null;
        $state = (string) $request->query('state', 'active');

        $page = Organization::query()
            ->when($q !== null && $q !== '', function ($w) use ($q): void {
                $needle = '%'.mb_strtolower((string) $q).'%';
                $w->where(fn ($x) => $x->whereRaw('lower(name) like ?', [$needle])->orWhereRaw('lower(slug) like ?', [$needle]));
            })
            ->when($state === 'deleted', fn ($w) => $w->onlyTrashed())
            ->with('owner:id,name,email')
            ->withCount('members')
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json(['data' => [
            'organizations' => collect($page->items())->map(fn (Organization $o): array => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'type' => $o->type->value,
                'owner' => $o->owner?->email,
                'plan' => $o->subscription()?->plan?->code,
                'members_count' => $o->members_count,
                'deleted_at' => $o->deleted_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
            ])->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function show(PlanOrgEntitlements $entitlements, int $organization): JsonResponse
    {
        $org = Organization::withTrashed()->with('owner:id,name,email')->findOrFail($organization);
        $sub = $org->subscription();

        return response()->json(['data' => [
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'type' => $org->type->value,
            'owner_id' => $org->owner_id,
            'require_2fa' => $org->require_2fa,
            'deleted_at' => $org->deleted_at?->toIso8601String(),
            'subscription' => $sub === null ? null : [
                'plan' => $sub->plan?->code,
                'status' => $sub->status->value,
                'admin_locked' => $sub->admin_locked,
                'trial_end' => $sub->trial_end?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(),
                'over_limit_until' => $sub->over_limit_until?->toIso8601String(),
            ],
            'effective_entitlements' => $entitlements->effective($org)->toArray(),
            'override' => OrganizationEntitlementOverride::query()->where('organization_id', $org->id)->value('overrides') ?? [],
        ]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function restore(Request $request, UserActionRequest $form, RestoreOrganizationAction $action, int $organization): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $org = $action->handle($actor, $organization);

        return response()->json(['data' => ['id' => $org->id, 'deleted_at' => null]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function plan(Request $request, ChangeOrgPlanRequest $form, ChangeOrgPlanAction $action, int $organization): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $org = Organization::query()->findOrFail($organization);

        $plan = $action->handle($actor, $org, (string) $form->validated('plan_code'));

        return response()->json(['data' => ['organization_id' => $org->id, 'plan' => $plan->code, 'admin_locked' => true]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function entitlements(Request $request, SetEntitlementOverrideRequest $form, SetOrgEntitlementOverrideAction $action, int $organization): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $org = Organization::query()->findOrFail($organization);

        $result = $action->handle($actor, $org, (array) $form->validated('entitlements'));

        return response()->json(['data' => ['organization_id' => $org->id, 'override' => $result]]);
    }
}
