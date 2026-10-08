<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\QueryOrgAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\OrgAuditIndexRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class OrgAuditController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{audit: array<int, object>, next_cursor: string|null, window_days: int}}')]
    public function index(OrgAuditIndexRequest $request, QueryOrgAuditAction $action): JsonResponse
    {
        $result = $action->handle(
            $request->organization(),
            $request->query('cursor') !== null ? (int) $request->query('cursor') : null,
            (int) $request->query('limit', 25),
        );

        return response()->json(['data' => [
            'audit' => $result['rows'],
            'next_cursor' => $result['next_cursor'],
            'window_days' => $result['window_days'],
        ]]);
    }
}
