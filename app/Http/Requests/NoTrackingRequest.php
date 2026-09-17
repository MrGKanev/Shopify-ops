<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Foundation\Http\FormRequest;

class NoTrackingRequest extends FormRequest
{
    use AuthorizesRunAudits, HasDateRangeRules;

    public function rules(): array
    {
        return $this->dateRangeRules() + ['threshold' => ['required', 'integer', 'min:1', 'max:8760']];
    }
}
