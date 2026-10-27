<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Models\OperationalField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationalFieldRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $isMetafield = $this->input('source_type') === OperationalField::SOURCE_METAFIELD;

        return [
            'alias' => ['required', 'string', 'max:80'],
            'source_type' => ['required', Rule::in([OperationalField::SOURCE_METAFIELD, OperationalField::SOURCE_CUSTOM_FIELD])],
            'source' => ['required', 'string', 'max:255', $isMetafield ? 'regex:/\A[A-Za-z0-9_-]{3,255}\.[A-Za-z0-9_-]{2,64}\z/' : Rule::in(['customField1', 'customField2', 'customField3'])],
            'required' => ['sometimes', 'boolean'],
        ];
    }
}
