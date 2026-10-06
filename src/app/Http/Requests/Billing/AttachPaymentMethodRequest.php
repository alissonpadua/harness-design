<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Org\OrgScopedRequest;

final class AttachPaymentMethodRequest extends OrgScopedRequest
{
    protected string $permission = 'billing.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'max:64'],
        ];
    }
}
