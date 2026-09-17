<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesAdministration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SendTestEmailRequest extends FormRequest
{
    use AuthorizesAdministration;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower($this->string('email')->trim()->toString())]);
    }
}
