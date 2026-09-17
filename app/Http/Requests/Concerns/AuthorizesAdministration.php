<?php

namespace App\Http\Requests\Concerns;

trait AuthorizesAdministration
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-administration') ?? false;
    }
}
