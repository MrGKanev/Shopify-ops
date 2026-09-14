<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ip', 'attempts', 'first_attempt_at', 'banned_until'])]
class LoginAttempt extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_attempt_at' => 'datetime',
            'banned_until' => 'datetime',
        ];
    }
}
