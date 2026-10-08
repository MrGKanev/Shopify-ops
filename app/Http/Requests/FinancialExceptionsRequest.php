<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class FinancialExceptionsRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'payout_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,19}$/D'],
            'after' => ['nullable', 'string', 'max:512'],
            'max_age_days' => ['required', 'integer', 'between:1,365'],
            'adjustment_threshold' => ['nullable', 'string', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D'],
            'tolerance' => ['required', 'string', 'regex:/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D'],
        ];
    }
}
