<?php

namespace App\Http\Requests;

use App\Application\Orders\BuildRemediationPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewOrderRemediationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(array_keys(BuildRemediationPlan::ACTIONS))],
            'order_numbers' => ['required', 'array', 'min:1', 'max:50'],
            'order_numbers.*' => ['required', 'string', 'distinct', 'max:100', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'tag' => ['required_if:action,add_tag,remove_tag', 'nullable', 'string', 'max:100'],
            'tag_id' => ['required_if:action,tag_shipstation', 'nullable', 'integer', 'min:1'],
            'hold_until' => ['required_if:action,hold', 'nullable', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.now()->addDays(30)->toDateString()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $numbers = $this->input('order_numbers');
        if (is_string($numbers)) {
            $numbers = preg_split('/[\s,;]+/', trim($numbers), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($numbers)) {
            $this->merge(['order_numbers' => array_map(fn (mixed $number): mixed => is_scalar($number) ? ltrim(trim((string) $number), '#') : $number, $numbers)]);
        }
    }
}
