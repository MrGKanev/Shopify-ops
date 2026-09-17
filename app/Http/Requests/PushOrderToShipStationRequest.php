<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasOrderNumberRules;
use Illuminate\Foundation\Http\FormRequest;

class PushOrderToShipStationRequest extends FormRequest
{
    use AuthorizesRunAudits, HasOrderNumberRules;

    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:64', $this->orderNumberRule()]];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('order_number'))) {
            $this->merge(['order_number' => $this->stripOrderNumberHash($this->input('order_number'))]);
        }
    }
}
