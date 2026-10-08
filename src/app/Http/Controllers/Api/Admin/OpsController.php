<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Billing\ProcessWebhookEvent;
use App\Audit\AuditSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserActionRequest;
use App\Models\User;
use App\Models\WebhookEvent;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class OpsController extends Controller
{
    public function __construct(private readonly AuditSecurityEvent $audit) {}

    #[Response(status: 200, type: 'array{data: array{url: string, expires_in: int}}')]
    public function horizonUrl(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $url = URL::temporarySignedRoute('horizon.index', now()->addSeconds(60));

        $this->audit->log('horizon_link', $admin, $admin, ['ttl_seconds' => 60]);

        return response()->json(['data' => ['url' => $url, 'expires_in' => 60]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function replayWebhook(Request $request, UserActionRequest $form, ProcessWebhookEvent $process, int $event): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $row = WebhookEvent::query()->findOrFail($event);

        $result = $process->handle((string) $row->gateway, (array) $row->payload, force: true);

        $this->audit->log('webhook_replay', $admin, $row, ['outcome' => $result->outcome, 'gateway_event_id' => $row->gateway_event_id]);

        return response()->json(['data' => [
            'event_id' => $result->eventId,
            'type' => $result->type,
            'outcome' => $result->outcome,
        ]]);
    }
}
