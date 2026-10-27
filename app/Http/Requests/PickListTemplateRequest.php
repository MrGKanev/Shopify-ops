<?php

namespace App\Http\Requests;

use App\Domain\Orders\PickListBuilder;
use App\Http\Requests\Concerns\AuthorizesRunAudits;
use App\Models\PickListTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PickListTemplateRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['string', 'distinct', Rule::in(array_keys(PickListBuilder::COLUMNS))],
            'sort' => ['required', Rule::in(['location', 'sku'])],
            'size' => ['required', Rule::in(PickListTemplate::SIZES)],
        ];
    }
}
