<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class TransferOwnershipRequest extends OrgScopedRequest
{
    protected string $permission = 'org.transfer';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_user_id' => ['required', 'integer'],
            'current_password' => ['required', 'string'],
            'otp' => ['nullable', 'string', 'max:32'],
        ];
    }
}
