<?php

namespace App\Application\Notifications;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, array{mode: string, threshold: int, include_zero: bool, email: string}> */
final readonly class EmailRules implements Arrayable
{
    /** @param array<string, EmailRule> $rules */
    public function __construct(public array $rules = []) {}

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $rules = [];
        foreach ($stored as $tool => $rule) {
            if (! is_string($tool) || ! is_array($rule) || ! in_array($rule['mode'] ?? null, ['off', 'immediate', 'digest'], true)) {
                continue;
            }
            $rules[$tool] = EmailRule::fromArray($tool, $rule);
        }

        return new self($rules);
    }

    public function forTool(string $tool): ?EmailRule
    {
        return $this->rules[$tool] ?? null;
    }

    /** @return array<string, array{mode: string, threshold: int, include_zero: bool, email: string}> */
    public function toArray(): array
    {
        return array_map(fn (EmailRule $rule): array => $rule->toArray(), $this->rules);
    }
}
