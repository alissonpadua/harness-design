<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ResendVerificationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class ResendVerificationController extends Controller
{
    #[Response(status: 202, description: 'Always accepted (anti-enumeration).', type: 'array{data: array{accepted: bool}}')]
    public function __invoke(ResendVerificationRequest $request, ResendVerificationAction $action): JsonResponse
    {
        $action->handle($request->validated('email'));

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
