<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOperationalIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:open,in_progress,resolved,ignored'],
            'priority' => ['required', 'in:low,normal,high,urgent'],
            'owner_user_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
