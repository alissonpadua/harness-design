<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class ForgotPasswordController extends Controller
{
    #[Response(status: 202, description: 'Always accepted (anti-enumeration).', type: 'array{data: array{accepted: bool}}')]
    public function __invoke(EmailRequest $request, SendPasswordResetLinkAction $action): JsonResponse
    {
        $action->handle($request->validated('email'));

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
