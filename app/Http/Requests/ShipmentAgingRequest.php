<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipmentAgingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    public function rules(): array
    {
        return ['threshold' => ['required', 'integer', 'min:1', 'max:365']];
    }
}
