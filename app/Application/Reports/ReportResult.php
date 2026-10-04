<?php

namespace App\Application\Reports;

/**
 * @template-covariant TRow of array<string, mixed>
 */
final readonly class ReportResult
{
    /**
     * @param  list<TRow>  $rows
     * @param  array<string, mixed>  $params  Report inputs, including date boundaries.
     * @param  array<string, mixed>  $meta  Report-specific summaries and source counters.
     */
    public function __construct(
        public array $rows,
        public int $scanned,
        public int $pages = 0,
        public bool $truncated = false,
        public array $params = [],
        public array $meta = [],
    ) {}

    /**
     * @return array{rows: list<TRow>, scanned: int, pages: int, truncated: bool, params: array<string, mixed>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['rows' => $this->rows, 'scanned' => $this->scanned, 'pages' => $this->pages, 'truncated' => $this->truncated, 'params' => $this->params, 'meta' => $this->meta];
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, scanned: int, pages: int, truncated: bool, params: array<string, mixed>, meta: array<string, mixed>}  $data
     * @return self<array<string, mixed>>
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
