<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmOrderRemediationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['group_uuid' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']];
    }
}
