<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\RevokeSessionAction;
use App\Data\Auth\SessionData;
use App\Data\Auth\SessionListData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RevokeSessionRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{sessions: array<int, array{id: int, device_type: string|null, ip_address: string|null, user_agent: string|null, created_at: string, last_used_at: string|null, current: bool}>}}')]
    public function index(Request $request): SessionListData
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = (int) $user->currentAccessToken()->id;

        return new SessionListData(
            $user->tokens()->latest()->get()
                ->map(fn ($token) => SessionData::make($token, $currentId))
                ->all()
        );
    }

    #[Response(status: 200, type: 'array{data: array{revoked: bool}}')]
    #[Response(status: 404, description: 'Unknown or foreign session id.', type: 'array{message: string}')]
    public function destroy(RevokeSessionRequest $request, RevokeSessionAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, (int) $request->validated('sessionId'));

        return response()->json(['data' => ['revoked' => true]]);
    }
}
