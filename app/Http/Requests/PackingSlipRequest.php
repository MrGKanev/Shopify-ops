<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasOrderNumberRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PackingSlipRequest extends FormRequest
{
    use HasOrderNumberRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:64', $this->orderNumberRule()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('order_number'))) {
            $this->merge(['order_number' => $this->stripOrderNumberHash((string) $this->input('order_number'))]);
        }
    }
}
