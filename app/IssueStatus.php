<?php

namespace App;

enum IssueStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    /**
     * Statuses that still need an operator's attention.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Open, self::InProgress];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Resolved => 'Resolved',
            self::Ignored => 'Ignored',
        };
    }
}
