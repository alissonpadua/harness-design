<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\LoginAction;
use App\Data\Auth\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Dedoc\Scramble\Attributes\Response;

final class LoginController extends Controller
{
    #[Response(status: 200, description: 'Token for this device type (previous same-type token revoked).', type: 'array{data: array{token: string, device_type: string}}')]
    #[Response(status: 401, description: 'Bad credentials / deleted / suspended (identical body).', type: 'array{message: string}')]
    #[Response(status: 403, description: 'Email not verified.', type: 'array{message: string}')]
    public function __invoke(LoginRequest $request, LoginAction $action): \Symfony\Component\HttpFoundation\Response
    {
        $token = $action->handle(
            LoginData::from($request->validated()),
            (string) $request->ip(),
            $request->userAgent(),
        );

        // Explicit 200 — spatie Data alone would default POST to 201.
        return $token->toResponse($request)->setStatusCode(200);
    }
}
