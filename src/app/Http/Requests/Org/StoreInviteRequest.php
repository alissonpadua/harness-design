<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Enums\OrgRole;
use Illuminate\Validation\Rule;

final class StoreInviteRequest extends OrgScopedRequest
{
    protected string $permission = 'members.invite';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(OrgRole::class)->except(OrgRole::Owner)],
        ];
    }
}
