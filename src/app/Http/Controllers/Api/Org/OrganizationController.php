<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\CreateOrganizationAction;
use App\Actions\Org\DeleteOrganizationAction;
use App\Actions\Org\SwitchOrganizationAction;
use App\Actions\Org\UpdateOrganizationAction;
use App\Data\Org\OrganizationData;
use App\Data\Org\OrgListData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\DeleteOrganizationRequest;
use App\Http\Requests\Org\StoreOrganizationRequest;
use App\Http\Requests\Org\SwitchOrganizationRequest;
use App\Http\Requests\Org\UpdateOrganizationRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class OrganizationController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{orgs: array<int, object>}}')]
    public function index(Request $request): OrgListData
    {
        /** @var User $user */
        $user = $request->user();

        $orgs = $user->organizations()->get();

        return new OrgListData(
            $orgs->map(fn ($org) => OrganizationData::make($org, $user, $org->membershipFor($user)?->role->value))->all()
        );
    }

    #[Response(status: 201, type: 'array{data: object}')]
    #[Response(status: 402, description: 'Team entitlement reached.', type: 'array{message: string}')]
    public function store(StoreOrganizationRequest $request, CreateOrganizationAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();
        $org = $action->handle($user, (string) $request->validated('name'));

        return OrganizationData::make($org, $user, 'owner')->toResponse($request)->setStatusCode(201);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function show(SwitchOrganizationRequest $request): OrganizationData
    {
        /** @var User $user */
        $user = $request->user();
        $org = $request->organization();

        return OrganizationData::make($org, $user, $org->membershipFor($user)?->role->value);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function update(UpdateOrganizationRequest $request, UpdateOrganizationAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();
        $org = $request->organization();

        $action->handle($org, $request->validated());

        return OrganizationData::make($org->refresh(), $user, $org->membershipFor($user)?->role->value)
            ->toResponse($request)->setStatusCode(200);
    }

    #[Response(status: 200, description: 'Soft deleted; restore is admin-only (spec 005).', type: 'array{data: array{deleted: bool}}')]
    public function destroy(DeleteOrganizationRequest $request, DeleteOrganizationAction $action): JsonResponse
    {
        $action->handle($request->organization(), (string) $request->validated('confirm_text'));

        return response()->json(['data' => ['deleted' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{current_organization_id: int}}')]
    public function switch(SwitchOrganizationRequest $request, SwitchOrganizationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $org = $request->organization();

        $action->handle($user, $org);

        return response()->json(['data' => ['current_organization_id' => $org->id]]);
    }
}
