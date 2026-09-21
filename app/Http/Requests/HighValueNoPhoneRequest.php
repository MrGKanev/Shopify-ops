<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HighValueNoPhoneRequest extends FormRequest
{
    use AuthorizesRunAudits, HasDateRangeRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->dateRangeRules() + [
            'minimum' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Za-z]{3}\z/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $currency = strtoupper(trim($this->input('currency')));
            $this->merge(['currency' => $currency === '' || $currency === 'ALL' ? null : $currency]);
        }
    }

}
