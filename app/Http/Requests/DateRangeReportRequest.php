<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Http\Requests\Concerns\HasDateRangeRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared request for reports whose only input is a start/end date range.
 */
class DateRangeReportRequest extends FormRequest
{
    use AuthorizesRunAudits, HasDateRangeRules;

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->dateRangeRules();
    }
}
