<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Foundation\Http\FormRequest;

class ShippingMarginRequest extends FormRequest
{
    use AuthorizesRunAudits, HasDateRangeRules;

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->dateRangeRules() + ['threshold' => ['required', 'numeric', 'min:1', 'max:1000000']];
    }
}
