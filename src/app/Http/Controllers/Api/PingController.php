<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\PingAction;
use App\Http\Controllers\Controller;
use App\Resources\PongResource;
use Illuminate\Http\JsonResponse;

final class PingController extends Controller
{
    public function __construct(private readonly PingAction $action) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(new PongResource($this->action->handle()));
    }
}
