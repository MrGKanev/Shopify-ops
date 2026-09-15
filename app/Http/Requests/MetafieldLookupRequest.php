<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetafieldLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['orders' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && count(preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)) > 20) {
                $fail('Maximum 20 order numbers at once.');
            }
        }], 'filter' => ['nullable', 'string', 'max:1000']];
    }
}
