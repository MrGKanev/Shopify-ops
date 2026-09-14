<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IgnoredOrderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'reason' => ['nullable', 'string', 'max:1000']];
    }
}
