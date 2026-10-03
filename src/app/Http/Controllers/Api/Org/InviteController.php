<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\AcceptInviteAction;
use App\Actions\Org\InviteMemberAction;
use App\Actions\Org\RevokeInviteAction;
use App\Data\Org\InviteData;
use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\AcceptInviteRequest;
use App\Http\Requests\Org\ListInvitesRequest;
use App\Http\Requests\Org\RevokeInviteRequest;
use App\Http\Requests\Org\StoreInviteRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class InviteController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{invites: array<int, object>}}')]
    public function index(ListInvitesRequest $request): SymfonyResponse
    {
        $invites = $request->organization()->invites()->outstanding()->get();

        return response()->json([
            'data' => ['invites' => $invites->map(fn ($invite) => InviteData::make($invite))->all()],
        ]);
    }

    #[Response(status: 201, type: 'array{data: object}')]
    #[Response(status: 402, description: 'Member entitlement reached.', type: 'array{message: string}')]
    public function store(StoreInviteRequest $request, InviteMemberAction $action): SymfonyResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $invite = $action->handle(
            $actor,
            $request->organization(),
            (string) $request->validated('email'),
            OrgRole::from((string) $request->validated('role')),
        );

        return InviteData::make($invite)->toResponse($request)->setStatusCode(201);
    }

    #[Response(status: 200, type: 'array{data: array{revoked: bool}}')]
    public function destroy(RevokeInviteRequest $request, RevokeInviteAction $action): JsonResponse
    {
        $action->handle($request->organization(), (int) $request->route('invite'));

        return response()->json(['data' => ['revoked' => true]]);
    }

    #[Response(status: 200, description: 'Joins with the invited role; does not switch current organization.', type: 'array{data: array{organization_id: int}}')]
    #[Response(status: 403, description: 'Invalid/expired/consumed or issued to a different email.', type: 'array{message: string}')]
    public function accept(AcceptInviteRequest $request, AcceptInviteAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $org = $action->handle($actor, (string) $request->validated('token'));

        return response()->json(['data' => ['organization_id' => $org->id]]);
    }
}
