<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ForceDeleteUserAction;
use App\Actions\Admin\ListUsersAction;
use App\Actions\Admin\RestoreUserAction;
use App\Actions\Admin\SuspendUserAction;
use App\Actions\Admin\UnsuspendUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForceDeleteUserRequest;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Http\Requests\Admin\UserActionRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

final class UserController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{users: array<int, object>, current_page: int, last_page: int}}')]
    public function index(Request $request, ListUsersAction $action): JsonResponse
    {
        $page = $action->handle(
            $request->query('q') !== null ? (string) $request->query('q') : null,
            (string) $request->query('status', 'active'),
            (int) $request->query('per_page', 25),
        );

        return response()->json(['data' => [
            'users' => collect($page->items())->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'suspended_at' => $u->suspended_at?->toIso8601String(),
                'deleted_at' => $u->deleted_at?->toIso8601String(),
                'orgs_count' => $u->memberships_count,
                'created_at' => $u->created_at?->toIso8601String(),
            ])->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function show(int $user): JsonResponse
    {
        $target = User::withTrashed()->with('memberships')->findOrFail($user);

        return response()->json(['data' => [
            'id' => $target->id,
            'name' => $target->name,
            'email' => $target->email,
            'email_verified_at' => $target->email_verified_at?->toIso8601String(),
            'two_factor_enabled' => $target->two_factor_confirmed_at !== null,
            'suspended_at' => $target->suspended_at?->toIso8601String(),
            'suspend_reason' => $target->suspend_reason,
            'deleted_at' => $target->deleted_at?->toIso8601String(),
            'organizations' => $target->memberships->map(fn ($m): array => [
                'id' => $m->organization_id, 'role' => $m->role->value, 'status' => $m->status->value,
            ])->all(),
            'tokens' => $target->tokens()->get(['id', 'name', 'abilities', 'last_used_at', 'impersonator_id'])->map
                ->toArray(),
            'recent_audit' => Activity::query()
                ->where('subject_type', User::class)
                ->where('subject_id', $target->id)
                ->latest('id')
                ->limit(20)
                ->get(['id', 'event', 'description', 'created_at'])
                ->toArray(),
        ]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function suspend(Request $request, SuspendUserRequest $form, SuspendUserAction $action, int $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $target = $action->handle(
            $actor,
            User::query()->findOrFail($user),
            (string) $form->validated('reason'),
        );

        return response()->json(['data' => [
            'id' => $target->id,
            'suspended_at' => $target->suspended_at?->toIso8601String(),
        ]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function unsuspend(Request $request, UserActionRequest $form, UnsuspendUserAction $action, int $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $action->handle($actor, User::query()->findOrFail($user));

        return response()->json(['data' => ['id' => $target->id, 'suspended_at' => null]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function restore(Request $request, UserActionRequest $form, RestoreUserAction $action, int $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $action->handle($actor, $user);

        return response()->json(['data' => ['id' => $target->id, 'deleted_at' => $target->deleted_at?->toIso8601String()]]);
    }

    #[Response(status: 200, type: 'array{data: array{deleted: bool}}')]
    public function destroy(Request $request, ForceDeleteUserRequest $form, ForceDeleteUserAction $action, int $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $user, (string) $form->validated('confirm_text'));

        return response()->json(['data' => ['deleted' => true]]);
    }
}
