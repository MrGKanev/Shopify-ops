<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Models\CarrierClaimDocument;
use Illuminate\Foundation\Http\FormRequest;

class CarrierClaimDocumentRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'max:'.CarrierClaimDocument::MAX_KILOBYTES, 'mimes:'.implode(',', array_keys(CarrierClaimDocument::TYPES)), 'mimetypes:'.implode(',', CarrierClaimDocument::TYPES)],
        ];
    }
}
