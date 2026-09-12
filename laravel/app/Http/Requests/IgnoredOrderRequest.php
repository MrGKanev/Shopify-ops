<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IgnoredOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:100', 'regex:/\d/'], 'reason' => ['nullable', 'string', 'max:1000']];
    }
}
