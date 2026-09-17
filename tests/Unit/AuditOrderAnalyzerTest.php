<?php

namespace Tests\Unit;

use App\Domain\Reports\AuditOrderAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditOrderAnalyzerTest extends TestCase
{
    public function test_it_classifies_ignored_skipped_number_email_and_missing_orders(): void
    {
        $orders = [
            ['name' => '#1001', 'financial_status' => 'paid', 'total_price' => 10],
            ['name' => '#1002', 'email' => 'buyer@example.com', 'financial_status' => 'paid', 'total_price' => 100],
            ['name' => '#1003', 'financial_status' => 'refunded', 'total_price' => 10],
            ['name' => '#1004', 'financial_status' => 'paid', 'total_price' => 10],
            ['name' => '#1005', 'financial_status' => 'paid', 'total_price' => 10],
            ['id' => 6, 'name' => '#1006', 'financial_status' => 'paid', 'total_price' => 10, 'shipping_lines' => []],
            ['id' => 7, 'name' => '#1007', 'financial_status' => 'paid', 'total_price' => 10, 'shipping_lines' => [['id' => 1]]],
        ];
        $shipstation = [
            ['orderNumber' => '1001', 'orderTotal' => 10],
            ['orderNumber' => 'other', 'customerEmail' => 'BUYER@example.com', 'orderTotal' => 101],
        ];

        $result = (new AuditOrderAnalyzer)->analyze($orders, $shipstation, ['1004' => ['reason' => 'known']], ['7' => true]);

        $this->assertCount(1, $result['found']);
        $this->assertSame('order_number', $result['found'][0]['match_method']);
        $this->assertSame(['financial', 'no_shipping', 'on_hold'], array_column($result['skipped'], 'skip_reason'));
        $this->assertCount(1, $result['ignored']);
        $this->assertSame(['#1002', '#1005'], array_column($result['missing'], 'name'));
    }

    public function test_it_matches_a_compound_shipstation_order_number_by_its_primary_segment(): void
    {
        // ShipStation multi-box shipments are named like "100042-B2": the
        // Shopify order is "100042", not the whole compound string.
        $orders = [['name' => '#100042', 'financial_status' => 'paid', 'total_price' => 10]];
        $shipstation = [['orderNumber' => '100042-B2', 'orderTotal' => 10]];

        $result = (new AuditOrderAnalyzer)->analyze($orders, $shipstation, []);

        $this->assertCount(1, $result['found']);
        $this->assertCount(0, $result['missing']);
    }

    public function test_it_does_not_false_match_an_unrelated_order_against_a_box_suffix_fragment(): void
    {
        // The "2" in "100042-B2" is a box-suffix artifact, not a real
        // standalone order number - it must not index under key "2".
        $orders = [['name' => '#2', 'financial_status' => 'paid', 'total_price' => 10]];
        $shipstation = [['orderNumber' => '100042-B2', 'orderTotal' => 10]];

        $result = (new AuditOrderAnalyzer)->analyze($orders, $shipstation, []);

        $this->assertCount(1, $result['missing']);
        $this->assertCount(0, $result['found']);
    }

    #[DataProvider('emailAmountToleranceProvider')]
    public function test_email_and_amount_fallback_match_uses_a_strict_less_than_one_percent_tolerance(float $shipstationTotal, bool $shouldMatch): void
    {
        $orders = [['name' => '#1001', 'email' => 'buyer@example.com', 'financial_status' => 'paid', 'total_price' => 100.0]];
        $shipstation = [['orderNumber' => 'other', 'customerEmail' => 'buyer@example.com', 'orderTotal' => $shipstationTotal]];

        $result = (new AuditOrderAnalyzer)->analyze($orders, $shipstation, []);

        $this->assertCount($shouldMatch ? 1 : 0, $result['found']);
        $this->assertCount($shouldMatch ? 0 : 1, $result['missing']);
    }

    /** @return array<string, array{0: float, 1: bool}> */
    public static function emailAmountToleranceProvider(): array
    {
        return [
            'within 1%, matches' => [100.50, true],
            'just under 1%, matches' => [100.99, true],
            'exactly 1%, does not match' => [101.00, false],
            'exceeds 1%, does not match' => [102.00, false],
        ];
    }

    public function test_email_and_amount_fallback_picks_the_closest_candidate_not_the_first(): void
    {
        // Repeat customer with two ShipStation orders under the same email.
        // The farther-off candidate (102.10, listed first) is within the 1%
        // tolerance but is not the real match; the closer one (102.95) is.
        // Picking "first satisfying" instead of "closest" would wrongly
        // attach the distant, unrelated order and mask a genuinely missing one.
        $orders = [['name' => '#1001', 'email' => 'buyer@example.com', 'financial_status' => 'paid', 'total_price' => 103.00]];
        $shipstation = [
            ['orderNumber' => 'other-1', 'customerEmail' => 'buyer@example.com', 'orderTotal' => 102.10],
            ['orderNumber' => 'other-2', 'customerEmail' => 'buyer@example.com', 'orderTotal' => 102.95],
        ];

        $result = (new AuditOrderAnalyzer)->analyze($orders, $shipstation, []);

        $this->assertCount(1, $result['found']);
        $this->assertSame('other-2', $result['found'][0]['shipstation_matches'][0]['orderNumber']);
    }
}
