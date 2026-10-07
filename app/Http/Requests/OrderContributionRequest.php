<?php

namespace App\Http\Requests;

class OrderContributionRequest extends DateRangeReportRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [...parent::rules(), 'after' => ['nullable', 'string', 'max:512']];
    }
}
