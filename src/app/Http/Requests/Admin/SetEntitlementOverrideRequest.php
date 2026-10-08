<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class SetEntitlementOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entitlements' => ['present', 'array', function (string $attribute, mixed $value, callable $fail): void {
                $allowed = ['max_teams', 'max_members_per_org', 'webhooks', 'audit_retention_days', 'api_rate_limit_per_min'];

                if (is_array($value) && array_diff(array_keys($value), $allowed) !== []) {
                    $fail('The entitlements payload contains unknown keys.');
                }
            }],
            'entitlements.max_teams' => ['sometimes', 'integer', 'min:1'],
            'entitlements.max_members_per_org' => ['sometimes', 'integer', 'min:1'],
            'entitlements.webhooks' => ['sometimes', 'boolean'],
            'entitlements.audit_retention_days' => ['sometimes', 'integer', 'min:0'],
            'entitlements.api_rate_limit_per_min' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
