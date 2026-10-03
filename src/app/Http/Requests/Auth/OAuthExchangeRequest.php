<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OAuthExchangeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:2048'],
            'device_type' => ['required', Rule::enum(DeviceType::class)],
            'otp' => ['nullable', 'string', 'max:32'],
        ];
    }
}
