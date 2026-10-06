<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BillingInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level role:super-admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:80'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'active' => ['sometimes', 'boolean'],
            'entitlements' => ['required', 'array'],
            'entitlements.max_teams' => ['required', 'integer', 'min:1'],
            'entitlements.max_members_per_org' => ['required', 'integer', 'min:1'],
            'entitlements.webhooks' => ['required', 'boolean'],
            'entitlements.audit_retention_days' => ['required', 'integer', 'min:0'],
            'entitlements.api_rate_limit_per_min' => ['required', 'integer', 'min:1'],
            'prices' => ['sometimes', 'array'],
            'prices.*.currency' => ['required', 'string', 'size:3'],
            'prices.*.interval' => ['required', Rule::in(array_column(BillingInterval::cases(), 'value'))],
            'prices.*.amount' => ['required', 'integer', 'min:0'],
            'prices.*.gateway_price_id' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
