<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasOrderNumberRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RateQuoteRequest extends FormRequest
{
    use AuthorizesRunAudits, HasOrderNumberRules;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:64', $this->orderNumberRule()],
            'carrier_code' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9_-]+\z/'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'weight_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'weight_unit' => ['required', Rule::in(['grams', 'ounces', 'pounds'])],
            'dimension_unit' => ['required', Rule::in(['centimeters', 'inches'])],
            'length' => ['required', 'numeric', 'gt:0', 'max:2000'],
            'width' => ['required', 'numeric', 'gt:0', 'max:2000'],
            'height' => ['required', 'numeric', 'gt:0', 'max:2000'],
            'package_code' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9_-]+\z/'],
            'confirmation' => ['required', Rule::in(['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'])],
            'residential' => ['required', 'boolean'],
            'tracking_required' => ['required', 'boolean'],
            'max_transit_days' => ['required', 'integer', 'between:1,30'],
            'minimum_coverage' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'allowed_services' => ['required', 'array', 'min:1', 'max:40'],
            'allowed_services.*' => ['required', 'string', 'distinct', 'max:100', 'regex:/\A[a-z0-9_-]+\z/'],
            'equivalence_confirmed' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $services = $this->input('allowed_services');
        if (is_string($services)) {
            $services = preg_split('/[\s,;]+/', mb_strtolower(trim($services)), -1, PREG_SPLIT_NO_EMPTY);
        }
        $input = ['allowed_services' => $services];
        foreach (['order_number', 'carrier_code', 'currency'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $input[$key] = match ($key) {
                    'order_number' => $this->stripOrderNumberHash($value),
                    'carrier_code' => mb_strtolower(trim($value)),
                    'currency' => mb_strtoupper(trim($value)),
                };
            }
        }
        $this->merge($input);
    }
}
