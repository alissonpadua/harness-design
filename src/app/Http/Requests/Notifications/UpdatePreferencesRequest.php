<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePreferencesRequest extends FormRequest
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
            'notifications' => ['required', 'array', 'min:1'],
            'notifications.*' => ['array'],
            'notifications.*.email' => ['required', 'boolean'],
        ];
    }
}
