<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Actions\Billing\ProcessWebhookEvent;
use App\Contracts\Billing\PaymentGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tunnel-less completion path: after hosted checkout the browser lands here,
 * we PULL the session by id from our own gateway (never trust the query param
 * as data — only as a lookup key) and replay it through the webhook pipeline.
 */
final class CheckoutReturnController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, ProcessWebhookEvent $process): Response
    {
        $sessionId = (string) $request->query('session_id', '');
        $event = $sessionId !== '' ? $gateway->checkoutSession($sessionId) : null;

        if ($event === null) {
            return response()->view('billing.checkout-return', [
                'ok' => false,
                'note' => 'Checkout session not found (or already consumed before it reached us). Nothing was changed.',
            ], 404);
        }

        $result = $process->handle($gateway->name(), $event);

        return response()->view('billing.checkout-return', [
            'ok' => true,
            'note' => sprintf('Session %s — %s. Close this tab and return to the app; state is now mirrored (webhook remains the source of truth once deployed).',
                $result->eventId, ucfirst($result->outcome)),
        ]);
    }
}
