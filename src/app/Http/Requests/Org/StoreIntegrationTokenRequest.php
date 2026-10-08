<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

/**
 * POST {org}/tokens (spec 006). Abilities must be permission-catalog names;
 * the 2FA account gate lives in the action (Q2=C / Q3=A).
 */
final class StoreIntegrationTokenRequest extends OrgScopedRequest
{
    protected string $permission = 'tokens.manage';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $catalog = array_keys((array) config('permissions.catalog'));

        return [
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', $catalog)],
        ];
    }
}
