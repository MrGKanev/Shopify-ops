<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NoteFlagRequest extends FormRequest
{
    use AuthorizesRunAudits, HasDateRangeRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->dateRangeRules() + ['keywords' => ['required', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && array_filter(array_map('trim', explode(',', $value))) === []) {
                $fail('Enter at least one keyword.');
            }
        }]];
    }
}
