<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class ManageInviteLinksRequest extends OrgScopedRequest
{
    protected string $permission = 'invite_links.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
