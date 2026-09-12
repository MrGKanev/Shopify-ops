<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushOrderToShipStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:64', 'regex:/\A#?[a-zA-Z0-9_-]+\z/']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('order_number'))) {
            $this->merge(['order_number' => ltrim(trim($this->input('order_number')), '#')]);
        }
    }
}
