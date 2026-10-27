<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Models\OperationalFieldFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationalFieldFilterRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'field' => ['required', 'integer'],
            'condition' => ['required', Rule::in(OperationalFieldFilter::CONDITIONS)],
            'text' => ['nullable', 'string', 'max:120', 'required_if:condition,contains'],
        ];
    }
}
