<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_type' => ['required', Rule::enum(DeviceType::class)],
            'otp' => ['nullable', 'string', 'max:32'],
        ];
    }
}
