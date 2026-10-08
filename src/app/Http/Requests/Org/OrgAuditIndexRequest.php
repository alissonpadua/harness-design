<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class OrgAuditIndexRequest extends OrgScopedRequest
{
    protected string $permission = 'audit.view';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
