<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class SuspendMemberRequest extends OrgScopedRequest
{
    protected string $permission = 'members.suspend';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
