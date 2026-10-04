<?php

namespace App;

enum IssuePriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function isElevated(): bool
    {
        return $this === self::High || $this === self::Urgent;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Badge tone used wherever an issue's priority is shown.
     */
    public function tone(): string
    {
        return match ($this) {
            self::High, self::Urgent => 'danger',
            self::Normal => 'warn',
            self::Low => 'default',
        };
    }
}
