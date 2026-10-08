<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class DeleteLogoRequest extends OrgScopedRequest
{
    protected string $permission = 'org.update';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
