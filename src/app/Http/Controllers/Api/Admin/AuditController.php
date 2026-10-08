<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\QueryAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminAuditIndexRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class AuditController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{audit: array<int, object>, next_cursor: string|null}}')]
    public function index(AdminAuditIndexRequest $request, QueryAuditAction $action): JsonResponse
    {
        $result = $action->handle([
            'subject_type' => $request->query('subject_type'),
            'subject_id' => $request->query('subject_id'),
            'causer_id' => $request->query('causer_id'),
            'event' => $request->query('event'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'cursor' => $request->query('cursor'),
            'limit' => (int) $request->query('limit', 25),
        ]);

        return response()->json(['data' => ['audit' => $result['rows'], 'next_cursor' => $result['next_cursor']]]);
    }
}
