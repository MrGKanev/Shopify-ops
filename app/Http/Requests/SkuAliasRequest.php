<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SkuAliasRequest extends FormRequest
{
    use AuthorizesAdministration;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'aliases' => ['present', 'array', 'list', 'max:200'],
            'aliases.*' => ['array:alias,master'],
            'aliases.*.alias' => ['nullable', 'required_with:aliases.*.master', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'aliases.*.master' => ['nullable', 'required_with:aliases.*.alias', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('aliases');
        if (is_array($rows)) {
            $this->merge(['aliases' => array_values(array_filter($rows, fn (mixed $row): bool => ! is_array($row) || ($row['alias'] ?? '') !== '' || ($row['master'] ?? '') !== ''))]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $aliases = [];
            foreach ($this->input('aliases', []) as $index => $row) {
                if (($row['alias'] ?? '') === '' && ($row['master'] ?? '') === '') {
                    continue;
                }
                $alias = (string) $row['alias'];
                if ($alias === $row['master'] || isset($aliases['sku:'.$alias])) {
                    $validator->errors()->add("aliases.$index.alias", __('Each alias must be unique and different from its master SKU.'));
                }
                $aliases['sku:'.$alias] = true;
            }
            foreach ($this->input('aliases', []) as $index => $row) {
                if (isset($aliases['sku:'.($row['master'] ?? '')])) {
                    $validator->errors()->add("aliases.$index.master", __('A master SKU cannot also be an alias. Use direct mappings without chains or cycles.'));
                }
            }
        }];
    }

    /** @return list<array{alias: string, master: string}> */
    public function mappings(): array
    {
        return array_values(array_filter($this->validated('aliases'), fn (array $row): bool => ($row['alias'] ?? '') !== '' && ($row['alias'] ?? null) !== null));
    }
}
