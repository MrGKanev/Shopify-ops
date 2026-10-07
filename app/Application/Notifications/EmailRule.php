<?php

namespace App\Application\Notifications;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, bool|int|string> */
final readonly class EmailRule implements Arrayable
{
    public function __construct(public string $mode, public int $threshold, public bool $includeZero, public string $email) {}

    /** @param array<string, mixed> $stored */
    public static function fromArray(string $tool, array $stored): self
    {
        $mode = $stored['mode'] ?? 'off';
        $email = trim((string) ($stored['email'] ?? ''));

        return new self(
            in_array($mode, ['off', 'immediate', 'digest'], true) ? $mode : 'off',
            max(in_array($tool, ['run_audit', 'operational_digest'], true) ? 0 : 1, (int) ($stored['threshold'] ?? 1)),
            (bool) ($stored['include_zero'] ?? false),
            $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        );
    }

    public function matches(int $rows): bool
    {
        return $this->mode !== 'off' && $rows >= $this->threshold && ($rows > 0 || $this->includeZero);
    }

    public function recipient(?string $defaultEmail): string
    {
        return $this->email !== '' ? $this->email : trim((string) $defaultEmail);
    }

    /** @return array{mode: string, threshold: int, include_zero: bool, email: string} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'threshold' => $this->threshold, 'include_zero' => $this->includeZero, 'email' => $this->email];
    }
}
