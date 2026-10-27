<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class CarrierClaimCommunicationRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:1000']];
    }
}
