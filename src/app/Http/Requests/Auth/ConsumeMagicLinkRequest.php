<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConsumeMagicLinkRequest extends FormRequest
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
            'token' => ['required', 'string', 'size:64'],
            'device_type' => ['required', Rule::enum(DeviceType::class)],
        ];
    }
}
