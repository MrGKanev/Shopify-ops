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

    /** @return array{name:string,email:string,password:string,label:string,slug:string,shopify_store:string,shopify_access_token:string,shipstation_api_key:?string,shipstation_api_secret:?string,store_number:?string,db_connection:string,database:string,db_host:?string,db_port:?string,db_username:?string,db_password:?string,mail_mailer:string,mail_host:?string,mail_port:?string,mail_username:?string,mail_password:?string,mail_encryption:string,mail_from_address:?string,mail_from_name:?string,slack_webhook_url:?string,discord_webhook_url:?string} */
    public function installationData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'label' => $this->string('label')->toString(),
            'slug' => $this->string('slug')->toString(),
            'shopify_store' => $this->string('shopify_store')->toString(),
            'shopify_access_token' => $this->string('shopify_access_token')->toString(),
            'shipstation_api_key' => $this->filled('shipstation_api_key') ? $this->string('shipstation_api_key')->toString() : null,
            'shipstation_api_secret' => $this->filled('shipstation_api_secret') ? $this->string('shipstation_api_secret')->toString() : null,
            'store_number' => $this->filled('store_number') ? $this->string('store_number')->toString() : null,
            'db_connection' => $this->string('db_connection')->toString(),
            'database' => $this->string('database')->toString(),
            'db_host' => $this->filled('db_host') ? $this->string('db_host')->toString() : null,
            'db_port' => $this->filled('db_port') ? $this->string('db_port')->toString() : null,
            'db_username' => $this->filled('db_username') ? $this->string('db_username')->toString() : null,
            'db_password' => $this->filled('db_password') ? $this->string('db_password')->toString() : null,
            'mail_mailer' => $this->string('mail_mailer')->toString(),
            'mail_host' => $this->filled('mail_host') ? $this->string('mail_host')->toString() : null,
            'mail_port' => $this->filled('mail_port') ? $this->string('mail_port')->toString() : null,
            'mail_username' => $this->filled('mail_username') ? $this->string('mail_username')->toString() : null,
            'mail_password' => $this->filled('mail_password') ? $this->string('mail_password')->toString() : null,
            'mail_encryption' => $this->string('mail_encryption')->toString(),
            'mail_from_address' => $this->filled('mail_from_address') ? $this->string('mail_from_address')->toString() : null,
            'mail_from_name' => $this->filled('mail_from_name') ? $this->string('mail_from_name')->toString() : null,
            'slack_webhook_url' => $this->filled('slack_webhook_url') ? $this->string('slack_webhook_url')->toString() : null,
            'discord_webhook_url' => $this->filled('discord_webhook_url') ? $this->string('discord_webhook_url')->toString() : null,
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
