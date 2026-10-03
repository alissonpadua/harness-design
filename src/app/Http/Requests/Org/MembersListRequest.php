<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class MembersListRequest extends OrgScopedRequest
{
    protected string $permission = 'members.view';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
