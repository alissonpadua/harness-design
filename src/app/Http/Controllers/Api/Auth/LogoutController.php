<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\LogoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NoBodyRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class LogoutController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{revoked: bool}}')]
    public function __invoke(NoBodyRequest $request, LogoutAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
