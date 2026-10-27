<?php

namespace App\Http\Requests;

use App\Domain\Orders\CustomerFulfillmentContext;
use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerContextFilterRequest extends FormRequest
{
    use AuthorizesRunAudits;

    protected function prepareForValidation(): void
    {
        $parts = preg_split('/[\s,;]+/', (string) $this->input('milestones', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->merge(['milestones' => array_values(array_unique($parts))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'rule' => ['required', Rule::in(CustomerFulfillmentContext::RULES)],
            'vip' => ['required', 'integer', 'min:2', 'max:1000'],
            'milestones' => ['array', 'max:10'],
            'milestones.*' => ['integer', 'min:2', 'max:1000'],
        ];
    }
}
