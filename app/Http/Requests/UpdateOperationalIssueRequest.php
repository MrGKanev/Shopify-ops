<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOperationalIssueRequest extends FormRequest
{
    use AuthorizesRunAudits;

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
