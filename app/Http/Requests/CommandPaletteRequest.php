<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommandPaletteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:64'],
        ];
    }
}
