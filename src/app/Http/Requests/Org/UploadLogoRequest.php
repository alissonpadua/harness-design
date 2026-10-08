<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

final class UploadLogoRequest extends OrgScopedRequest
{
    protected string $permission = 'org.update';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'max:2048'],
        ];
    }
}
