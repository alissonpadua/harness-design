<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\LogoutAllAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NoBodyRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class LogoutAllController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{revoked_count: int}}')]
    public function __invoke(NoBodyRequest $request, LogoutAllAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => ['revoked_count' => $action->handle($user)]]);
    }
}
