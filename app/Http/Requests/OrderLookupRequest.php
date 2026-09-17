<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasOrderNumberRules;
use Illuminate\Foundation\Http\FormRequest;

class OrderLookupRequest extends FormRequest
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
            'order_number' => ['nullable', 'string', 'max:64', $this->orderNumberRule()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('order_number')) {
            $value = $this->input('order_number');

            if (! is_string($value)) {
                return;
            }

            $orderNumber = $this->stripOrderNumberHash($value);

            $this->merge([
                'order_number' => $orderNumber === '' ? null : $orderNumber,
            ]);
        }
    }
}
