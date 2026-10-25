<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class ShipmentMovementRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:100', 'regex:/^#?[a-zA-Z0-9_-]+$/D'], 'acceptance_hours' => ['required', 'integer', 'between:1,720'], 'stale_hours' => ['required', 'integer', 'between:1,2160']];
    }
}
