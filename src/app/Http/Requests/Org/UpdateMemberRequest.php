<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Enums\OrgRole;
use Illuminate\Validation\Rule;

final class UpdateMemberRequest extends OrgScopedRequest
{
    protected string $permission = 'members.update_role';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(OrgRole::class)],
        ];
    }
}
