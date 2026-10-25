<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class ReportScheduleRequest extends FormRequest
{
    use AuthorizesAdministration;

    /** @return array<string, list<string|RequiredIf>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'source_run_id' => ['nullable', 'integer', 'min:1'],
            'tool' => ['nullable', 'in:shipment_backlog,shopify_backlog,shipment_movement_monitor,run_audit'],
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone:all'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'month_day' => ['required', 'integer', 'between:1,28'],
            'lookback_days' => ['required', 'integer', 'between:1,365'],
            'end_offset_days' => ['required', 'integer', 'between:0,30'],
            'notification_enabled' => ['required', 'boolean'],
            'notification_threshold' => ['required', 'integer', 'between:1,100000'],
            'notification_email' => ['nullable', Rule::requiredIf($this->boolean('notification_enabled')), 'email:rfc', 'max:254'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['enabled' => $this->input('enabled', false), 'notification_enabled' => $this->input('notification_enabled', false)]);
    }
}
