<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Actions\Billing\ProcessWebhookEvent;
use App\Contracts\Billing\PaymentGateway;
use App\Exceptions\InvalidWebhookException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StripeWebhookRequest;
use Illuminate\Http\JsonResponse;

/**
 * Stripe-shaped webhook ingest (signature-verified) — also exercised by the
 * FakeGateway in tests. Dev without a public URL uses `php artisan billing:ingest`.
 */
final class WebhookController extends Controller
{
    public function __invoke(StripeWebhookRequest $request, PaymentGateway $gateway, ProcessWebhookEvent $process): JsonResponse
    {
        try {
            $event = $gateway->ingest($request->getContent(), $request->header('Webhook-Signature') ?? $request->header('Stripe-Signature'));
        } catch (InvalidWebhookException) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $result = $process->handle($gateway->name(), $event);

        return response()->json(['data' => [
            'event_id' => $result->eventId,
            'outcome' => $result->outcome,
        ]]);
    }
}
