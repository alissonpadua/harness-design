<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class AdminAuditIndexRequest extends FormRequest
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
            'subject_type' => ['sometimes', 'string', 'max:120'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'causer_id' => ['sometimes', 'integer', 'min:1'],
            'event' => ['sometimes', 'string', 'max:60'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'cursor' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
