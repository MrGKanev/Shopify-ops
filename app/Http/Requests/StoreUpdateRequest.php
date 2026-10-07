<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesAdministration;
use App\Models\Store;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreUpdateRequest extends FormRequest
{
    use AuthorizesAdministration;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');

        return [
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('stores')->ignore($store)],
            'label' => ['required', 'string', 'max:255'],
            'shopify_store' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('stores')->ignore($store)],
            'shopify_access_token' => ['nullable', 'string', 'max:2048'],
            'shopify_webhook_secret' => ['nullable', 'string', 'max:2048'],
            'shipstation_api_key' => ['nullable', 'string', 'max:2048'],
            'shipstation_api_secret' => ['nullable', 'string', 'max:2048'],
            'store_number' => ['nullable', 'string', 'max:255'],
            'scheduled_audit_enabled' => ['nullable', 'boolean'],
            'scheduled_audit_time' => ['nullable', 'date_format:H:i', 'required_if:scheduled_audit_enabled,1'],
            'operational_digest_policy' => ['sometimes', 'array:sla_days,lookback_days'],
            'operational_digest_policy.sla_days' => ['required_with:operational_digest_policy', 'integer', 'between:1,365'],
            'operational_digest_policy.lookback_days' => ['required_with:operational_digest_policy', 'integer', 'between:1,365'],
            'return_exception_policy' => ['sometimes', 'array:approval_days,processing_days,exchange_days'],
            'return_exception_policy.approval_days' => ['required_with:return_exception_policy', 'integer', 'between:1,90'],
            'return_exception_policy.processing_days' => ['required_with:return_exception_policy', 'integer', 'between:1,90'],
            'return_exception_policy.exchange_days' => ['required_with:return_exception_policy', 'integer', 'between:1,90'],
            'delivery_watch_days' => ['required', 'integer', 'between:1,90'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::lower($this->string('slug')->trim()->toString()),
            'shopify_store' => Str::of($this->string('shopify_store')->toString())
                ->trim()
                ->lower()
                ->replaceEnd('.myshopify.com', '')
                ->toString(),
        ]);
    }
}
