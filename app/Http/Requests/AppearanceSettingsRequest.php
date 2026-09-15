<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class AppearanceSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-administration') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:80'],
            'logo' => ['nullable', File::image()->max('2mb')->dimensions(Rule::dimensions()->maxWidth(1600)->maxHeight(800)), 'mimes:jpg,jpeg,png,webp'],
            'login_image' => ['nullable', File::image()->max('5mb')->dimensions(Rule::dimensions()->maxWidth(3840)->maxHeight(3840)), 'mimes:jpg,jpeg,png,webp'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_login_image' => ['nullable', 'boolean'],
            'custom_links' => ['array', 'max:5'],
            'custom_links.*.label' => ['required', 'string', 'max:40'],
            'custom_links.*.url' => ['required', 'url:http,https', 'max:2048'],
            'custom_links.*.audience' => ['required', Rule::in(['all', 'operator', 'admin'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'custom_links.*.url.required' => 'Моля, въведете адрес за допълнителния линк.',
            'custom_links.*.url.url' => 'Адресът на допълнителния линк трябва да започва с http:// или https://.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $links = collect($this->input('custom_links', []))
            ->filter(fn (mixed $link): bool => is_array($link))
            ->map(fn (array $link): array => [
                'label' => trim((string) ($link['label'] ?? '')),
                'url' => trim((string) ($link['url'] ?? '')),
                'audience' => (string) ($link['audience'] ?? 'all'),
            ])
            ->filter(fn (array $link): bool => $link['label'] !== '' || $link['url'] !== '')
            ->values()
            ->all();

        $this->merge([
            'site_name' => $this->string('site_name')->trim()->toString(),
            'custom_links' => $links,
        ]);
    }
}
