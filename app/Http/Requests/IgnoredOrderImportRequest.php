<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class IgnoredOrderImportRequest extends FormRequest
{
    use AuthorizesRunAudits;

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'reason' => ['nullable', 'string', 'max:1000']];
    }
}
