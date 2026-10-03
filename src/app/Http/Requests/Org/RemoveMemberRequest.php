<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class RemoveMemberRequest extends OrgScopedRequest
{
    protected string $permission = 'members.remove';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
