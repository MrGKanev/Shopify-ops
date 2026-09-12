<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveOrderNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'digits_between:1,20'],
            'order_number' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $orderNumber = $this->input('order_number');
        $this->merge([
            'note' => trim((string) $this->input('note')),
            'order_number' => is_string($orderNumber) ? ltrim(trim($orderNumber), '#') : $orderNumber,
        ]);
    }
}
