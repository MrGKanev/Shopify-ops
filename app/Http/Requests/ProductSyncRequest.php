<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class ProductSyncRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tare.min' => __('Package tare must be zero or positive.')];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:catalog,shipment'],
            'order_number' => ['nullable', 'required_if:mode,shipment', 'string', 'max:100', 'regex:/^#?[a-zA-Z0-9_-]+$/D'],
            'shipment_id' => ['nullable', 'integer', 'min:1'],
            'tare' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'tare_unit' => ['required', 'in:grams,kilograms,ounces,pounds'],
            'dim_divisor' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'dim_basis' => ['required', 'in:cm_kg,in_lb'],
        ];
    }
}
