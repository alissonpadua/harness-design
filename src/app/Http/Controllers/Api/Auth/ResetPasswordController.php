<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ResetPasswordAction;
use App\Data\Auth\ResetPasswordData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class ResetPasswordController extends Controller
{
    #[Response(status: 200, description: 'Password rotated; all previous sessions revoked.', type: 'array{data: array{reset: bool}}')]
    #[Response(status: 422, description: 'Invalid token / email (identical) or weak password.', type: 'array{message: string, errors: object}')]
    public function __invoke(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->handle(ResetPasswordData::from($request->validated()));

        return response()->json(['data' => ['reset' => true]]);
    }
}
