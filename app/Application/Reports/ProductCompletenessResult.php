<?php

namespace App\Application\Reports;

readonly class ProductCompletenessResult extends RowsResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public int $scanned,
        array $rows,
        public int $critical,
        public int $warnings,
        public int $pages,
        public bool $truncated,
    ) {
        parent::__construct($rows);
    }
}
