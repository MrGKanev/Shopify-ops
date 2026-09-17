<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class IgnoredOrderRequest extends FormRequest
{
    use AuthorizesRunAudits;

    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:100', 'regex:/\d/'], 'reason' => ['nullable', 'string', 'max:1000']];
    }
}
