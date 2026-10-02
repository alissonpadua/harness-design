<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\RegisterUserAction;
use App\Data\Auth\RegisterUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    #[Response(
        status: 202,
        description: 'Accepted — verification email sent when the account is new or unverified. Always the same body (anti-enumeration).',
        type: 'array{data: array{accepted: bool}}',
    )]
    public function __invoke(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $action->handle(RegisterUserData::from($request->validated()));

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
