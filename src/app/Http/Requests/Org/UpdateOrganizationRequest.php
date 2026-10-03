<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Enums\OrgRole;
use Illuminate\Validation\Rule;

final class UpdateOrganizationRequest extends OrgScopedRequest
{
    protected string $permission = 'org.update';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'domain' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255'],
            'default_member_role' => ['sometimes', Rule::enum(OrgRole::class)->except(OrgRole::Owner)],
            'require_2fa' => ['sometimes', 'boolean'],
            'invite_only' => ['sometimes', 'boolean'],
        ];
    }
}
