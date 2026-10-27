<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Models\CarrierClaimPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarrierClaimPaymentRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(CarrierClaimPayment::KINDS)],
            'amount' => ['required', 'regex:/\A\d{1,12}(\.\d{1,6})?\z/', 'not_regex:/\A0+(\.0+)?\z/'],
            'currency' => ['required', 'regex:/\A[A-Za-z]{3}\z/'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'source_note' => ['required', 'string', 'max:255'],
            'ledger_reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array{kind: string, amount: string, currency: string, received_on: string, source_note: string, ledger_reference: string|null} */
    public function payment(): array
    {
        return [
            'kind' => $this->string('kind')->toString(),
            'amount' => $this->string('amount')->toString(),
            'currency' => $this->string('currency')->toString(),
            'received_on' => $this->string('received_on')->toString(),
            'source_note' => $this->string('source_note')->toString(),
            'ledger_reference' => $this->filled('ledger_reference') ? $this->string('ledger_reference')->toString() : null,
        ];
    }
}
