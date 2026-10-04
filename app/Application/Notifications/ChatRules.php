<?php

namespace App\Application\Notifications;

use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, bool|int|string> */
final readonly class ChatRules implements Arrayable
{
    public function __construct(public RowRule $audit, public RowRule $scan, public string $mentions = '', private bool $withMentions = true) {}

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored, bool $withMentions = true): self
    {
        preg_match_all('/[UWS][A-Z0-9]{8,}/', strtoupper((string) ($stored['mentions'] ?? '')), $mentionIds);

        return new self(
            new RowRule((bool) ($stored['audit_enabled'] ?? true), max(0, (int) ($stored['audit_min_missing'] ?? 0)), (bool) ($stored['include_zero_audit'] ?? true)),
            new RowRule((bool) ($stored['scan_enabled'] ?? false), max(1, (int) ($stored['scan_min_rows'] ?? 1))),
            implode(' ', array_unique($mentionIds[0])),
            $withMentions,
        );
    }

    /** @return array{audit_enabled: bool, audit_min_missing: int, include_zero_audit: bool, scan_enabled: bool, scan_min_rows: int, mentions?: string} */
    public function toArray(): array
    {
        return ['audit_enabled' => $this->audit->enabled, 'audit_min_missing' => $this->audit->minimum, 'include_zero_audit' => $this->audit->includeZero, 'scan_enabled' => $this->scan->enabled, 'scan_min_rows' => $this->scan->minimum, ...($this->withMentions ? ['mentions' => $this->mentions] : [])];
    }
}
