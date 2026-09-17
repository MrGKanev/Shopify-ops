<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasOrderNumberRules;
use Illuminate\Foundation\Http\FormRequest;

class OrderComparisonRequest extends FormRequest
{
    use HasOrderNumberRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order_a' => ['nullable', 'required_with:order_b', 'string', 'max:64', $this->orderNumberRule()],
            'order_b' => ['nullable', 'required_with:order_a', 'string', 'max:64', $this->orderNumberRule()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'order_a.required_with' => 'Enter two order numbers to compare.',
            'order_b.required_with' => 'Enter two order numbers to compare.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['order_a', 'order_b'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $orderNumber = $this->stripOrderNumberHash($value);
            $this->merge([$field => $orderNumber === '' ? null : $orderNumber]);
        }
    }
}
