<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class ShipmentAgingRequest extends FormRequest
{
    use AuthorizesRunAudits;

    public function rules(): array
    {
        return ['threshold' => ['required', 'integer', 'min:1', 'max:365']];
    }
}
