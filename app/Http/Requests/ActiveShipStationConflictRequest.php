<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Foundation\Http\FormRequest;

class ActiveShipStationConflictRequest extends FormRequest
{
    use HasDateRangeRules;

    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    public function rules(): array
    {
        return $this->dateRangeRules();
    }
}
