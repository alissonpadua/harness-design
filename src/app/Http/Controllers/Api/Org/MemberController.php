<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\LeaveOrganizationAction;
use App\Actions\Org\RemoveMemberAction;
use App\Actions\Org\SetMemberStatusAction;
use App\Actions\Org\TransferOwnershipAction;
use App\Actions\Org\UpdateMemberRoleAction;
use App\Data\Org\MemberData;
use App\Data\Org\MemberListData;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\LeaveOrganizationRequest;
use App\Http\Requests\Org\MembersListRequest;
use App\Http\Requests\Org\RemoveMemberRequest;
use App\Http\Requests\Org\SuspendMemberRequest;
use App\Http\Requests\Org\TransferOwnershipRequest;
use App\Http\Requests\Org\UpdateMemberRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class MemberController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{members: array<int, object>}}')]
    public function index(MembersListRequest $request): MemberListData
    {
        $members = $request->organization()->members()->get();

        return new MemberListData($members->map(fn ($user) => MemberData::make($user))->all());
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function update(UpdateMemberRequest $request, UpdateMemberRoleAction $action): MemberData
    {
        $target = $action->handle(
            $request->organization(),
            (int) $request->route('user'),
            OrgRole::from((string) $request->validated('role')),
        );

        return MemberData::make($target);
    }

    #[Response(status: 200, type: 'array{data: array{suspended: bool}}')]
    public function suspend(SuspendMemberRequest $request, SetMemberStatusAction $action): JsonResponse
    {
        $action->handle($request->organization(), (int) $request->route('user'), MemberStatus::Suspended);

        return response()->json(['data' => ['suspended' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{suspended: bool}}')]
    public function unsuspend(SuspendMemberRequest $request, SetMemberStatusAction $action): JsonResponse
    {
        $action->handle($request->organization(), (int) $request->route('user'), MemberStatus::Active);

        return response()->json(['data' => ['suspended' => false]]);
    }

    #[Response(status: 200, type: 'array{data: array{removed: bool}}')]
    public function destroy(RemoveMemberRequest $request, RemoveMemberAction $action): JsonResponse
    {
        $action->handle($request->organization(), (int) $request->route('user'));

        return response()->json(['data' => ['removed' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{left: bool}}')]
    public function leave(LeaveOrganizationRequest $request, LeaveOrganizationAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $request->organization());

        return response()->json(['data' => ['left' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{transferred: bool}}')]
    public function transfer(TransferOwnershipRequest $request, TransferOwnershipAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle(
            $actor,
            $request->organization(),
            (int) $request->validated('to_user_id'),
            (string) $request->validated('current_password'),
            $request->validated('otp'),
        );

        return response()->json(['data' => ['transferred' => true]]);
    }
}
