<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class ListInvitesRequest extends OrgScopedRequest
{
    protected string $permission = 'invites.view';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
