<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class CarrierClaimPolicyRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:80'],
            'account' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
            'source_url' => ['required', 'string', 'max:500', 'url:https'],
            'verified_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
