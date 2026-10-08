<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class IntegrationTokenIndexRequest extends OrgScopedRequest
{
    protected string $permission = 'tokens.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
