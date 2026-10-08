<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Profile;

use App\Actions\Profile\ChangePasswordAction;
use App\Actions\Profile\DeleteAccountAction;
use App\Actions\Profile\RequestEmailChangeAction;
use App\Actions\Profile\UpdateProfileAction;
use App\Data\Profile\ProfileData;
use App\Data\Profile\UpdateProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangeEmailRequest;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\DeleteAccountRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class ProfileController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{id: int, name: string, email: string, email_verified_at: string|null, locale: string, timezone: string}}')]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => ProfileData::make($user)->toArray() + ['impersonation' => $user->currentImpersonation()]]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): ProfileData
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, UpdateProfileData::from($request->validated()));

        return ProfileData::make($user->refresh());
    }

    #[Response(status: 202, description: 'Confirmation mailed to both addresses; email changes only on confirm.', type: 'array{data: array{accepted: bool}}')]
    public function email(ChangeEmailRequest $request, RequestEmailChangeAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, (string) $request->validated('email'), (string) $request->validated('password'));

        return response()->json(['data' => ['accepted' => true]], 202);
    }

    #[Response(status: 200, type: 'array{data: array{changed: bool}}')]
    public function password(ChangePasswordRequest $request, ChangePasswordAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle(
            $user,
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
            (int) $user->currentAccessToken()->id,
        );

        return response()->json(['data' => ['changed' => true]]);
    }

    #[Response(status: 200, description: 'Soft delete; restore is admin-only (spec 005).', type: 'array{data: array{deleted: bool}}')]
    public function deleteAccount(DeleteAccountRequest $request, DeleteAccountAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, (string) $request->validated('password'));

        return response()->json(['data' => ['deleted' => true]]);
    }
}
