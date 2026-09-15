<?php

namespace App\Application\Reports;

readonly class CustomerLookupResult
{
    /** @param list<array<string, mixed>> $orders @param array<string, mixed>|null $customer @param array<string, int> $tags */
    public function __construct(public string $email, public array $orders, public ?array $customer, public float $totalSpent, public string $currency, public int $paid, public int $cancelled, public array $tags, public int $pages, public bool $truncated) {}
}
