<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Enums\OrgRole;
use Illuminate\Validation\Rule;

final class StoreInviteLinkRequest extends OrgScopedRequest
{
    protected string $permission = 'invite_links.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(OrgRole::class)->except(OrgRole::Owner)],
            'expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
