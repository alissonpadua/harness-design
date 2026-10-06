<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\BillingInterval;
use App\Http\Requests\Org\OrgScopedRequest;
use Illuminate\Validation\Rule;

final class CheckoutRequest extends OrgScopedRequest
{
    protected string $permission = 'billing.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:40'],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
