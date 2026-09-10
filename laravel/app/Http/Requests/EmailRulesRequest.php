<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmailRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-administration') ?? false;
    }

    public function rules(): array
    {
        return ['rules' => ['required', 'array'], 'rules.*.mode' => ['required', 'in:off,immediate,digest'], 'rules.*.threshold' => ['required', 'integer', 'min:0', 'max:100000'], 'rules.*.include_zero' => ['required', 'boolean'], 'rules.*.email' => ['required_unless:rules.*.mode,off', 'nullable', 'email:rfc', 'max:254']];
    }

    protected function prepareForValidation(): void
    {
        $rules = [];
        foreach ((array) $this->input('rules') as $tool => $rule) {
            if (is_string($tool) && preg_match('/^[a-z0-9_-]{1,80}$/', $tool) && is_array($rule)) {
                $rules[$tool] = [...$rule, 'include_zero' => filter_var($rule['include_zero'] ?? false, FILTER_VALIDATE_BOOL), 'email' => trim((string) ($rule['email'] ?? ''))];
            }
        }
        $this->merge(['rules' => $rules]);
    }
}
