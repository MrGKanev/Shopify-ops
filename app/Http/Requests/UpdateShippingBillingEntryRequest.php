<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class UpdateShippingBillingEntryRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, list<string|RequiredIf>> */
    public function rules(): array
    {
        return ['dispute_status' => ['required', 'in:none,review,submitted,accepted,rejected'], 'review_note' => ['nullable', 'string', 'max:2000', Rule::requiredIf($this->boolean('excluded'))], 'excluded' => ['required', 'boolean'], 'expected_refund' => ['nullable', 'string', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D'], 'refund_due_at' => ['nullable', 'date_format:Y-m-d', 'required_with:expected_refund']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['excluded' => $this->input('excluded', false)]);
    }
}
