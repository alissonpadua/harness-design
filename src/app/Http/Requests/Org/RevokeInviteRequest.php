<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class RevokeInviteRequest extends OrgScopedRequest
{
    protected string $permission = 'invites.revoke';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
