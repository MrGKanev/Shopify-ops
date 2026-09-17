<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerLtvRequest extends FormRequest
{
    use HasDateRangeRules;

    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return $this->dateRangeRules();
    }
}
