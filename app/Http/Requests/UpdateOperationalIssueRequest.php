<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\IssuePriority;
use App\IssueStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOperationalIssueRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(IssueStatus::class)],
            'priority' => ['required', Rule::enum(IssuePriority::class)],
            'owner_user_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
