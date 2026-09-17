<?php

namespace App\Http\Requests\Concerns;

trait HasDateRangeRules
{
    /** @return array<string, array<int, string>> */
    private function dateRangeRules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
