<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SlackRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-administration') ?? false;
    }

    public function rules(): array
    {
        return ['audit_enabled' => ['required', 'boolean'], 'audit_min_missing' => ['required', 'integer', 'min:0', 'max:100000'], 'include_zero_audit' => ['required', 'boolean'], 'scan_enabled' => ['required', 'boolean'], 'scan_min_rows' => ['required', 'integer', 'min:1', 'max:100000'], 'mentions' => ['nullable', 'string', 'max:500']];
    }

    protected function prepareForValidation(): void
    {
        preg_match_all('/[UWS][A-Z0-9]{8,}/', strtoupper((string) $this->input('mentions')), $matches);
        $this->merge(['audit_enabled' => $this->boolean('audit_enabled'), 'include_zero_audit' => $this->boolean('include_zero_audit'), 'scan_enabled' => $this->boolean('scan_enabled'), 'mentions' => implode(' ', array_unique($matches[0]))]);
    }
}
