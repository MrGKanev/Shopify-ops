<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TaxAuditRequest extends FormRequest
{
    use HasDateRangeRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->dateRangeRules() + ['minimum' => ['required', 'numeric', 'min:0', 'max:1000000']];
    }
}
