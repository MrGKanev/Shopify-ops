<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DiscountAbuseRequest extends FormRequest
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
        return $this->dateRangeRules() + ['minimum_emails' => ['required', 'integer', 'min:2', 'max:100']];
    }
}
