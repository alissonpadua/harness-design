<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class RevokeSessionRequest extends FormRequest
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
            'sessionId' => ['required', 'integer'],
        ];
    }

    /**
     * Merge the route parameter into the validated payload so the path id
     * is actually enforced, not just the (empty) request body.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return array_merge(parent::validationData(), ['sessionId' => $this->route('sessionId')]);
    }
}
