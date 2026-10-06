<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Billing\UpsertPlanAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Plan;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlanController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{plans: array<int, object>}}')]
    public function index(Request $request): JsonResponse
    {
        $plans = Plan::query()->with('prices')->orderBy('id')->get()
            ->map(fn (Plan $p): array => $this->view($p))->all();

        return response()->json(['data' => ['plans' => $plans]]);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function show(Plan $plan): JsonResponse
    {
        return response()->json(['data' => $this->view($plan->load('prices'))]);
    }

    #[Response(status: 201, type: 'array{data: object}')]
    public function store(StorePlanRequest $request, UpsertPlanAction $action): JsonResponse
    {
        return response()->json(['data' => $this->view($action->create($request->validated()))], 201);
    }

    #[Response(status: 200, type: 'array{data: object}')]
    public function update(UpdatePlanRequest $request, Plan $plan, UpsertPlanAction $action): JsonResponse
    {
        return response()->json(['data' => $this->view($action->update($plan, $request->validated()))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'trial_days' => $plan->trial_days,
            'active' => $plan->active,
            'entitlements' => $plan->entitlements->toArray(),
            'prices' => $plan->prices->map(fn ($p): array => [
                'currency' => $p->currency,
                'interval' => $p->interval->value,
                'amount' => $p->amount,
                'gateway_price_id' => $p->gateway_price_id,
            ])->all(),
        ];
    }
}
