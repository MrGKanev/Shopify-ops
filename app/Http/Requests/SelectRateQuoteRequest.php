<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class SelectRateQuoteRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['service_code' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9_-]+\z/'], 'confirmed' => ['required', 'accepted']];
    }
}
