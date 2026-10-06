<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlanRequest extends FormRequest
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
        return array_map(
            fn (array $r): array => $this->makeOptional($r),
            (new StorePlanRequest)->rules()
        );
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private function makeOptional(array $rules): array
    {
        foreach ($rules as $i => $rule) {
            if ($rule === 'required') {
                $rules[$i] = 'sometimes';
            }
        }

        $rules[] = 'sometimes';

        return array_values(array_unique($rules));
    }
}
