<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Empty-body FormRequest for body-less POST endpoints (logout, logout-all).
 * Keeps the "every write route has a FormRequest" arch rule universal.
 */
final class NoBodyRequest extends FormRequest
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
        return [];
    }
}
