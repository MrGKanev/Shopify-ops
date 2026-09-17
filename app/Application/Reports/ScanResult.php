<?php

namespace App\Application\Reports;

/**
 * Shared result shape for reports that scan a date range (or full catalogue) and return paginated rows.
 *
 * @template TRow
 */
readonly class ScanResult
{
    /**
     * @param  list<TRow>  $rows
     */
    public function __construct(
        public int $scanned,
        public array $rows,
        public ?int $pages = null,
        public ?bool $truncated = null,
        public ?string $startDate = null,
        public ?string $endDate = null,
        public ?int $threshold = null,
        public ?int $minimum = null,
        public ?int $minimumEmails = null,
        public ?int $skippedMissingCountry = null,
        public ?int $days = null,
        public ?int $totalVariants = null,
    ) {}
}
