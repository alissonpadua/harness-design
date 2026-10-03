<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ConfirmTwoFactorAction;
use App\Actions\Auth\DisableTwoFactorAction;
use App\Actions\Auth\EnrollTwoFactorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\NoBodyRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class TwoFactorController extends Controller
{
    #[Response(status: 200, description: 'Enrollment payload (TOTP not active until confirm).', type: 'array{data: array{secret: string, provisioning_uri: string, qr_data_url: string}}')]
    public function enroll(NoBodyRequest $request, EnrollTwoFactorAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $action->handle($user)->toResponse($request)->setStatusCode(200);
    }

    #[Response(status: 200, description: 'Recovery codes — shown exactly once.', type: 'array{data: array{recovery_codes: array<int,string>}}')]
    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactorAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $action->handle($user, (string) $request->validated('code'), (int) $user->currentAccessToken()->id)
            ->toResponse($request)->setStatusCode(200);
    }

    #[Response(status: 200, type: 'array{data: array{disabled: bool}}')]
    public function disable(DisableTwoFactorRequest $request, DisableTwoFactorAction $action): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, (string) $request->validated('password'));

        return response()->json(['data' => ['disabled' => true]]);
    }
}
