<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class DeleteOrganizationRequest extends OrgScopedRequest
{
    protected string $permission = 'org.delete';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_text' => ['required', 'string'],
        ];
    }
}
