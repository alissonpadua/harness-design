<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\CreateInviteLinkAction;
use App\Actions\Org\JoinInviteLinkAction;
use App\Actions\Org\RevokeInviteLinkAction;
use App\Data\Org\InviteLinkData;
use App\Data\Org\InviteLinkSecretData;
use App\Enums\OrgRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\AcceptInviteRequest;
use App\Http\Requests\Org\ManageInviteLinksRequest;
use App\Http\Requests\Org\StoreInviteLinkRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class InviteLinkController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{links: array<int, object>}}')]
    public function index(ManageInviteLinksRequest $request): JsonResponse
    {
        $links = $request->organization()->inviteLinks()->outstanding()->get();

        return response()->json([
            'data' => ['links' => $links->map(fn ($link) => InviteLinkData::make($link))->all()],
        ]);
    }

    #[Response(status: 201, description: 'Raw token shown once.', type: 'array{data: array{id: int, token: string, url: string, role: string, uses: int}}')]
    public function store(StoreInviteLinkRequest $request, CreateInviteLinkAction $action): SymfonyResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $link = $action->handle(
            $actor,
            $request->organization(),
            OrgRole::from((string) $request->validated('role')),
            $request->validated('expires_in_days') !== null ? (int) $request->validated('expires_in_days') : null,
            $request->validated('max_uses') !== null ? (int) $request->validated('max_uses') : null,
        );

        return InviteLinkSecretData::make($link)->toResponse($request)->setStatusCode(201);
    }

    #[Response(status: 200, type: 'array{data: array{revoked: bool}}')]
    public function destroy(ManageInviteLinksRequest $request, RevokeInviteLinkAction $action): JsonResponse
    {
        $action->handle($request->organization(), (int) $request->route('link'));

        return response()->json(['data' => ['revoked' => true]]);
    }

    #[Response(status: 200, description: 'Joins (idempotent for existing members).', type: 'array{data: array{organization_id: int}}')]
    #[Response(status: 403, description: 'Invalid/expired/exhausted link.', type: 'array{message: string}')]
    public function join(AcceptInviteRequest $request, JoinInviteLinkAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $org = $action->handle($actor, (string) $request->validated('token'));

        return response()->json(['data' => ['organization_id' => $org->id]]);
    }
}
