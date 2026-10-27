<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Models\CarrierClaim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CarrierClaimRequest extends FormRequest
{
    use AuthorizesRunAudits;

    private const AMOUNT = 'regex:/\A\d{1,12}(\.\d{1,6})?\z/';

    protected function prepareForValidation(): void
    {
        $lines = preg_split('/\R/', (string) $this->input('evidence', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->merge(['evidence' => array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:80'],
            'account' => ['nullable', 'string', 'max:120'],
            'external_reference' => ['required', 'string', 'max:120'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            'operational_issue_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(CarrierClaim::STATUSES)],
            'currency' => ['required', 'regex:/\A[A-Za-z]{3}\z/'],
            'goods_requested' => ['nullable', self::AMOUNT],
            'goods_approved' => ['nullable', self::AMOUNT],
            'postage_requested' => ['nullable', self::AMOUNT],
            'postage_approved' => ['nullable', self::AMOUNT],
            'submitted_on' => ['nullable', 'date', 'before_or_equal:today'],
            'decision_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:submitted_on'],
            'follow_up_on' => ['nullable', 'date'],
            'deadline_on' => ['nullable', 'date'],
            'deadline_policy' => ['nullable', 'string', 'max:255'],
            'carrier_claim_policy_id' => ['nullable', 'integer', 'min:1'],
            'dates_verified' => ['sometimes', 'boolean'],
            'evidence' => ['array', 'max:10'],
            'evidence.*' => ['string', 'max:500', 'url:https'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $input = $validator->getData();
            $approved = filled($input['goods_approved'] ?? null) || filled($input['postage_approved'] ?? null) || in_array($input['status'] ?? '', ['approved', 'paid'], true);
            if ($approved && empty($input['evidence'])) {
                $validator->errors()->add('evidence', __('Add at least one https evidence link before recording an approval or payment.'));
            }
            if (in_array($input['status'] ?? '', ['submitted', 'under_review', 'approved', 'paid'], true) && blank($input['submitted_on'] ?? null)) {
                $validator->errors()->add('submitted_on', __('Enter the submission date from the carrier portal.'));
            }
            if (in_array($input['status'] ?? '', ['denied', 'approved', 'paid'], true) && blank($input['decision_on'] ?? null)) {
                $validator->errors()->add('decision_on', __('Enter the decision date from the carrier portal.'));
            }
            if (filled($input['deadline_on'] ?? null) && blank($input['deadline_policy'] ?? null) && blank($input['carrier_claim_policy_id'] ?? null)) {
                $validator->errors()->add('deadline_policy', __('Choose a verified policy or describe the deadline policy.'));
            }
            $hasDate = filled($input['submitted_on'] ?? null) || filled($input['decision_on'] ?? null) || filled($input['deadline_on'] ?? null);
            if ($hasDate && ! ($input['dates_verified'] ?? false)) {
                $validator->errors()->add('dates_verified', __('Confirm that you checked these dates in the carrier or insurer portal.'));
            }
        });
    }
}
