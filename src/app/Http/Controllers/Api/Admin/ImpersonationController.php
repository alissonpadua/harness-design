<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\StartImpersonationAction;
use App\Actions\Admin\StopImpersonationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserActionRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ImpersonationController extends Controller
{
    #[Response(status: 201, type: 'array{data: array{token: string, token_id: int, target: object, started_at: string}}')]
    public function store(Request $request, UserActionRequest $form, StartImpersonationAction $action, int $user): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $result = $action->handle($admin, User::query()->findOrFail($user));

        return response()->json(['data' => [
            'token' => $result['token'],
            'token_id' => $result['token_id'],
            'target' => ['id' => $result['target']->id, 'name' => $result['target']->name, 'email' => $result['target']->email],
            'started_at' => $result['started_at'],
        ]], 201);
    }

    #[Response(status: 200, type: 'array{data: array{impersonations: array<int, object>}}')]
    public function index(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        /** @var Collection<int, PersonalAccessToken> $rows */
        $rows = $admin->activeImpersonations()->with('tokenable')->latest('id')->get();

        return response()->json(['data' => ['impersonations' => $rows->map(fn (PersonalAccessToken $t): array => [
            'token_id' => $t->id,
            'impersonator_id' => (int) $t->getAttribute('impersonator_id'),
            'target_id' => (int) $t->tokenable_id,
            'target_email' => $t->tokenable?->getAttribute('email'),
            'started_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
        ])->all()]]);
    }

    #[Response(status: 200, type: 'array{data: array{stopped: bool}}')]
    public function destroy(Request $request, UserActionRequest $form, StopImpersonationAction $action, int $token): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $action->handle($admin, $token);

        return response()->json(['data' => ['stopped' => true]]);
    }
}
