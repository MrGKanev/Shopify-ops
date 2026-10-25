<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesRunAudits;
use Illuminate\Foundation\Http\FormRequest;

class ShipmentMovementMonitorRequest extends FormRequest
{
    use AuthorizesRunAudits;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
