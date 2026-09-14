<?php

declare(strict_types=1);

use App\Domain\Reports\CustomerOrderSummaryAnalyzer;
use PHPUnit\Framework\TestCase;
use Shopify\GraphQL\Client;
use Shopify\GraphQL\CustomerOrderInsights;

final class CustomerLookupParityTest extends TestCase
{
    public function test_spend_and_currency_summary_match_legacy(): void
    {
        $rawOrders = [
            ['totalPriceSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'EUR']]],
            ['totalPriceSet' => ['shopMoney' => ['amount' => '25.50', 'currencyCode' => 'EUR']]],
        ];
        $client = new class($rawOrders) extends Client
        {
            public function __construct(private readonly array $orders) {}

            public function paginateGraphQL(string $queryTemplate, string $rootKey, callable $processor, int $maxPages = 20): array
            {
                $processor(array_map(static fn (array $order): array => ['node' => $order], $this->orders));

                return ['truncated' => false, 'pages' => 1];
            }
        };
        $legacy = (new CustomerOrderInsights($client))->lookupCustomer('customer@example.com');
        $laravel = (new CustomerOrderSummaryAnalyzer)->analyze([
            ['total_price' => '50.00', 'currency' => 'EUR'],
            ['total_price' => '25.50', 'currency' => 'EUR'],
        ]);

        $this->assertSame($legacy['totalSpent'], $laravel['total_spent']);
        $this->assertSame($legacy['currency'], $laravel['currency']);
    }
}
