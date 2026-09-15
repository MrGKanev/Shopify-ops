<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetafieldSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['namespace' => ['required', 'string', 'max:255'], 'key' => ['required', 'string', 'max:255'], 'value' => ['nullable', 'string', 'max:1000'], 'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']];
    }
}
