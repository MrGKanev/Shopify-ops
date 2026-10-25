<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class ShippingBillingImportRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'source_account' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'], 'statement_reference' => ['required', 'string', 'max:160']];
    }
}
