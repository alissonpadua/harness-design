<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class AdminPingController extends Controller
{
    #[Response(status: 200, description: 'Admin plane alive (super-admin only).', type: 'array{data: array{scope: string, pong: bool}}')]
    #[Response(status: 403, description: 'Authenticated but not super-admin.', type: 'array{message: string}')]
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => ['scope' => 'admin', 'pong' => true]]);
    }
}
