<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InstallApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'db_connection' => ['required', Rule::in(['sqlite', 'mysql', 'mariadb'])],
            'database' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'db_host' => [Rule::requiredIf(fn (): bool => $this->input('db_connection') !== 'sqlite'), 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'db_port' => [Rule::requiredIf(fn (): bool => $this->input('db_connection') !== 'sqlite'), 'nullable', 'integer', 'between:1,65535'],
            'db_username' => [Rule::requiredIf(fn (): bool => $this->input('db_connection') !== 'sqlite'), 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'db_password' => ['nullable', 'string', 'max:2048', 'not_regex:/[\r\n]/'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'label' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'shopify_store' => ['required', 'string', 'max:255', 'alpha_dash'],
            'shopify_access_token' => ['required', 'string', 'max:2048'],
            'shipstation_api_key' => ['nullable', 'string', 'max:2048'],
            'shipstation_api_secret' => ['nullable', 'string', 'max:2048'],
            'store_number' => ['nullable', 'string', 'max:255'],
            'mail_mailer' => ['required', Rule::in(['log', 'smtp'])],
            'mail_host' => [Rule::requiredIf(fn (): bool => $this->input('mail_mailer') === 'smtp'), 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'mail_port' => [Rule::requiredIf(fn (): bool => $this->input('mail_mailer') === 'smtp'), 'nullable', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'mail_password' => ['nullable', 'string', 'max:2048', 'not_regex:/[\r\n]/'],
            'mail_encryption' => ['nullable', Rule::in(['', 'tls'])],
            'mail_from_address' => [Rule::requiredIf(fn (): bool => $this->input('mail_mailer') === 'smtp'), 'nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'slack_webhook_url' => ['nullable', 'url', 'max:2048'],
            'discord_webhook_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower($this->string('email')->trim()->toString()),
            'slug' => Str::lower($this->string('slug')->trim()->toString()),
            'shopify_store' => Str::of($this->string('shopify_store')->toString())
                ->trim()
                ->lower()
                ->replaceEnd('.myshopify.com', '')
                ->toString(),
            'shipstation_api_key' => $this->nullableString('shipstation_api_key'),
            'shipstation_api_secret' => $this->nullableString('shipstation_api_secret'),
            'store_number' => $this->nullableString('store_number'),
            'mail_mailer' => $this->input('mail_mailer', 'log') === 'smtp' ? 'smtp' : 'log',
            'mail_host' => $this->nullableString('mail_host'),
            'mail_username' => $this->nullableString('mail_username'),
            'mail_password' => $this->nullableString('mail_password'),
            'mail_encryption' => $this->string('mail_encryption')->trim()->toString() === 'tls' ? 'tls' : '',
            'mail_from_address' => $this->nullableString('mail_from_address'),
            'mail_from_name' => $this->nullableString('mail_from_name'),
            'slack_webhook_url' => $this->nullableString('slack_webhook_url'),
            'discord_webhook_url' => $this->nullableString('discord_webhook_url'),
        ]);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->string($key)->trim()->toString();

        return $value === '' ? null : $value;
    }
}
