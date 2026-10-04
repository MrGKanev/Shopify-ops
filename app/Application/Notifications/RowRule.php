<?php

namespace App\Application\Notifications;

final readonly class RowRule
{
    public function __construct(public bool $enabled, public int $minimum, public bool $includeZero = true) {}

    public function matches(int $rows): bool
    {
        return $this->enabled && $rows >= $this->minimum && ($rows > 0 || $this->includeZero);
    }
}
