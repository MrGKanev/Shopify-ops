<?php

namespace App\Http\Requests\Concerns;

trait AuthorizesRunAudits
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }
}
