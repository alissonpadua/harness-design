<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Billing;

use App\Actions\Billing\AttachPaymentMethodAction;
use App\Actions\Billing\DetachPaymentMethodAction;
use App\Actions\Billing\EnsureOrgSubscription;
use App\Actions\Billing\PreviewPlanChange;
use App\Actions\Billing\SetCancelAtPeriodEnd;
use App\Actions\Billing\SetDefaultPaymentMethodAction;
use App\Actions\Billing\StartCheckout;
use App\Actions\Billing\SwitchPlan;
use App\Actions\Billing\SyncInvoices;
use App\Contracts\Billing\PaymentGateway;
use App\Data\Billing\PaymentMethodData;
use App\Data\Billing\SubscriptionViewData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AttachPaymentMethodRequest;
use App\Http\Requests\Billing\BillingReadRequest;
use App\Http\Requests\Billing\CancelRequest;
use App\Http\Requests\Billing\CheckoutRequest;
use App\Http\Requests\Billing\PaymentMethodIdRequest;
use App\Http\Requests\Billing\PlanChangeRequest;
use App\Http\Requests\Billing\PreviewPlanChangeRequest;
use App\Http\Requests\Billing\SetupPaymentMethodRequest;
use App\Models\BillingInvoice;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BillingController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    #[Response(status: 201, type: 'array{data: array{url: string, plan_code: string, interval: string}}')]
    public function checkout(CheckoutRequest $request, StartCheckout $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle(
                $request->organization(),
                (string) $request->validated('plan_code'),
                (string) $request->validated('interval'),
                strtoupper((string) ($request->validated('currency') ?? 'USD')),
            ),
        ], 201);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function subscription(BillingReadRequest $request, EnsureOrgSubscription $ensure): SubscriptionViewData
    {
        return SubscriptionViewData::make($ensure->handle($request->organization()));
    }

    #[Response(status: 200, type: 'array{data: array{lines: array<int, array{description: string, amount: int, currency: string}>, total_due: int, currency: string}}')]
    public function preview(PreviewPlanChangeRequest $request, PreviewPlanChange $action): JsonResponse
    {
        $preview = $action->handle(
            $request->organization(),
            (string) $request->validated('plan_code'),
            strtoupper((string) ($request->validated('currency') ?? 'USD')),
        );

        return response()->json(['data' => [
            'lines' => array_map(static fn ($l): array => $l->toArray(), $preview->lines),
            'total_due' => $preview->totalDue,
            'currency' => $preview->currency,
        ]]);
    }

    #[Response(status: 200, type: 'array{data: array{changed: bool}}')]
    public function change(PlanChangeRequest $request, SwitchPlan $action): JsonResponse
    {
        $action->handle(
            $request->organization(),
            (string) $request->validated('plan_code'),
            strtoupper((string) ($request->validated('currency') ?? 'USD')),
        );

        return response()->json(['data' => ['changed' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{cancel_at_period_end: bool}}')]
    public function cancel(CancelRequest $request, SetCancelAtPeriodEnd $action): JsonResponse
    {
        $cancel = (bool) $request->validated('cancel');
        $action->handle($request->organization(), $cancel);

        return response()->json(['data' => ['cancel_at_period_end' => $cancel]]);
    }

    #[Response(status: 200, type: 'array{data: array{payment_methods: array<int, object>}}')]
    public function paymentMethods(BillingReadRequest $request): JsonResponse
    {
        $methods = $this->gateway->paymentMethods($request->organization());

        return response()->json([
            'data' => ['payment_methods' => array_map(
                fn (PaymentMethodData $pm): array => $pm->toArray(),
                $methods
            )],
        ]);
    }

    #[Response(status: 201, type: 'array{data: array{client_secret: string, url: string}}')]
    public function setup(SetupPaymentMethodRequest $request): JsonResponse
    {
        $intent = $this->gateway->setupIntent($request->organization());

        return response()->json(['data' => ['client_secret' => $intent->clientSecret, 'url' => $intent->url]], 201);
    }

    #[Response(status: 200, type: 'array{data: array{attached: bool}}')]
    public function attach(AttachPaymentMethodRequest $request, AttachPaymentMethodAction $action): JsonResponse
    {
        $action->handle($request->organization(), (string) $request->validated('payment_method_id'));

        return response()->json(['data' => ['attached' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{default: bool}}')]
    public function setDefault(PaymentMethodIdRequest $request, SetDefaultPaymentMethodAction $action): JsonResponse
    {
        $action->handle($request->organization(), (string) $request->route('paymentMethod'));

        return response()->json(['data' => ['default' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{detached: bool}}')]
    public function detach(PaymentMethodIdRequest $request, DetachPaymentMethodAction $action): JsonResponse
    {
        $action->handle($request->organization(), (string) $request->route('paymentMethod'));

        return response()->json(['data' => ['detached' => true]]);
    }

    #[Response(status: 200, type: 'array{data: array{invoices: array<int, object>}}')]
    public function invoices(BillingReadRequest $request, SyncInvoices $action): JsonResponse
    {
        $list = $action->handle($request->organization())->map(fn (BillingInvoice $i): array => [
            'id' => $i->id,
            'status' => $i->status,
            'amount_due' => $i->amount_due,
            'currency' => $i->currency,
            'hosted_url' => $i->hosted_url,
            'paid_at' => $i->paid_at?->toIso8601String(),
            'due_at' => $i->due_at?->toIso8601String(),
        ])->all();

        return response()->json(['data' => ['invoices' => $list]]);
    }

    #[Response(status: 200, type: 'array{data: array{url: string|null}}')]
    public function download(BillingReadRequest $request): JsonResponse
    {
        $invoice = BillingInvoice::query()
            ->where('organization_id', $request->organization()->id)
            ->whereKey((int) $request->route('invoice'))
            ->first() ?? throw new NotFoundHttpException;

        return response()->json(['data' => ['url' => $invoice->hosted_url]]);
    }
}
