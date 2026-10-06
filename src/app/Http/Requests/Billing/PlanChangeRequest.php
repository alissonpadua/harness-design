<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Org\OrgScopedRequest;

final class PlanChangeRequest extends OrgScopedRequest
{
    protected string $permission = 'billing.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:40'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
