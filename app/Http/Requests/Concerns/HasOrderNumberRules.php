<?php

namespace App\Http\Requests\Concerns;

trait HasOrderNumberRules
{
    private function orderNumberRule(): string
    {
        return 'regex:/\A#?[a-zA-Z0-9_-]+\z/';
    }

    private function stripOrderNumberHash(string $value): string
    {
        return ltrim(trim($value), '#');
    }

    private function isValidOrderNumber(string $value): bool
    {
        return mb_strlen($value) <= 64 && preg_match('/\A[a-zA-Z0-9_-]+\z/', $value) === 1;
    }
}
